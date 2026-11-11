<?php

/**
 * Parses a plain-text Umple state machine into a SmartState protocol
 * definition (schemaVersion 1) that the Java engine's
 * ProtocolDefinitionParser accepts.
 *
 * Supported constructs (the full vocabulary of the fasting-study models):
 *   - class declaration (brace on the same or next line), re-opened classes
 *   - attribute declarations (`Integer startDeadline = 0;`) — become timers
 *   - a single `state { ... }` machine of flat states
 *   - `event -> target;` transitions
 *   - `after(attribute) -> target;` timed transitions
 *   - `entry / { ... }` blocks (stateNotify calls are dropped — the engine
 *     logs state entry natively; other code is preserved as a description)
 *   - `} -> target;` chained after an entry block, and bare `-> target;`
 *     inside a state — both become an auto transition
 *   - `namespace`/`position` directives and trailing method stubs (ignored)
 *
 * Comments are attached to the state they precede or sit inside as a
 * `description` field; the engine ignores unknown keys, so descriptions
 * survive publish untouched.
 *
 * The scanner is defensive by construction: every loop is guaranteed to
 * advance (a stalled iteration skips one character), warnings/errors are
 * capped, and comment/brace scanning is string-literal aware, so arbitrary
 * hostile input degrades into warnings rather than hangs.
 */
class UmpleParser {

    private const MAX_MESSAGES = 50;

    private string $src;
    private int $len;
    private int $pos = 0;
    private array $comments = [];   // [offset => text]
    private array $errors = [];
    private array $warnings = [];
    private ?string $className = null;
    private array $attributes = []; // name => initializer string
    private array $states = [];     // name => [events, afters, auto, notes, start, end]
    private bool $machineParsed = false;

    /**
     * @return array{definition: ?array, className: ?string, errors: string[], warnings: string[]}
     */
    public static function parse(string $source): array {
        $instance = new self();
        $instance->run($source);
        return [
            'definition' => count($instance->errors) === 0 ? $instance->buildDefinition() : null,
            'className'  => $instance->className,
            'errors'     => $instance->errors,
            'warnings'   => $instance->warnings
        ];
    }

    private function run(string $source): void {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $this->src = $this->stripComments($source);
        $this->len = strlen($this->src);

        $last = -1;
        while ($this->pos < $this->len) {
            if ($this->pos === $last) {
                // Failsafe: an iteration that consumed nothing skips one character
                $this->pos++;
                continue;
            }
            $last = $this->pos;
            $this->skipWhitespace();
            if ($this->pos >= $this->len)
                break;
            if ($this->match('/\Gclass\s+([A-Za-z_]\w*)\s*\{/', $m)) {
                if (is_null($this->className))
                    $this->className = $m[1];
                $this->parseClassBody();
            } else if ($this->match('/\Gnamespace\b[^;\n]*;/')) {
                // UmpleOnline layout metadata
            } else if ($this->match('/\G\}/')) {
                $this->addWarning('Ignored a stray closing brace outside any class.');
            } else {
                $this->addWarning('Ignored unrecognized content outside any class: [' . $this->snippet() . ']');
                $this->resync();
            }
        }

        if (is_null($this->className))
            $this->addError('No Umple class declaration was found. Expected something like [class MyProtocol { ... }].');
        else if (!$this->machineParsed && count($this->errors) === 0)
            $this->addError('No state machine was found. Expected a [state { ... }] block inside the class.');
        else if (count($this->states) === 0 && count($this->errors) === 0)
            $this->addError('The state machine contains no states.');

        $this->validateModel();
    }

    /**
     * Replaces // and block comments with spaces, remembering each comment's
     * offset. Quote-aware so a // inside a string literal is left alone; an
     * unterminated quote stops mattering at the end of its line.
     */
    private function stripComments(string $source): string {
        $out = '';
        $len = strlen($source);
        $i = 0;
        while ($i < $len) {
            $ch = $source[$i];
            if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '/') {
                $end = strpos($source, "\n", $i);
                if ($end === false)
                    $end = $len;
                $text = trim(substr($source, $i + 2, $end - $i - 2));
                if ($text !== '')
                    $this->comments[strlen($out)] = $text;
                $out .= str_repeat(' ', $end - $i);
                $i = $end;
            } else if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '*') {
                $end = strpos($source, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $text = trim(preg_replace('/\s*\n\s*\*?\s*/', "\n", substr($source, $i + 2, max(0, $end - $i - 4))));
                if ($text !== '')
                    $this->comments[strlen($out)] = $text;
                $out .= preg_replace('/[^\n]/', ' ', substr($source, $i, $end - $i));
                $i = $end;
            } else if ($ch === '"' || $ch === "'") {
                $out .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $source[$i];
                    $out .= $c;
                    $i++;
                    if ($c === '\\' && $i < $len) {
                        $out .= $source[$i];
                        $i++;
                    } else if ($c === $ch || $c === "\n") {
                        break;
                    }
                }
            } else {
                $out .= $ch;
                $i++;
            }
        }
        return $out;
    }

    private function parseClassBody(): void {
        $last = -1;
        while ($this->pos < $this->len) {
            if ($this->pos === $last) {
                $this->pos++;
                continue;
            }
            $last = $this->pos;
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->addError('The file ended before the class was closed (missing }).');
                return;
            }
            if ($this->match('/\G\}/'))
                return;
            if ($this->match('/\Gposition\b[^;\n]*;/'))
                continue;
            // Method stub with an inline body, e.g. `boolean stateNotify(String node) {return true;}`
            // (possessive quantifiers: identifier-anchored units, so runs of spaces cannot backtrack)
            if ($this->match('/\G(?:[A-Za-z_][\w<>\[\],.]*+\s++)+[A-Za-z_]\w*\s*+\([^)]*+\)\s*+\{/')) {
                $this->skipBracedBlock(1);
                continue;
            }
            // Attribute, e.g. `Integer startDeadline = 0;` — the initializer may not
            // span lines or braces, so a forgotten semicolon fails locally
            if ($this->match('/\G[A-Za-z_]\w*\s+([A-Za-z_]\w*)\s*(?:=[ \t]*([^;{}\n]*))?;/', $m)) {
                $this->attributes[$m[1]] = trim($m[2] ?? '');
                continue;
            }
            // State machine, conventionally `state { ... }`
            if ($this->match('/\G([A-Za-z_]\w*)\s*\{/', $m)) {
                if ($this->machineParsed) {
                    $this->addWarning("Ignored additional state machine [{$m[1]}] — only the first one is imported.");
                    $this->skipBracedBlock(1);
                } else {
                    $this->machineParsed = true;
                    $this->parseStateMachine();
                }
                continue;
            }
            $this->addWarning('Ignored unrecognized content in class [' . $this->className . ']: [' . $this->snippet() . ']');
            $this->resync();
        }
    }

    private function parseStateMachine(): void {
        $last = -1;
        while ($this->pos < $this->len) {
            if ($this->pos === $last) {
                $this->pos++;
                continue;
            }
            $last = $this->pos;
            // Span starts before leading whitespace so comments that document a
            // state from above attach to it
            $spanStart = $this->pos;
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->addError('The file ended before the state machine was closed (missing }).');
                return;
            }
            if ($this->match('/\G\}/'))
                return;
            if ($this->match('/\G([A-Za-z_]\w*)\s*\{/', $m)) {
                if (isset($this->states[$m[1]])) {
                    $this->addError("Duplicate state name [{$m[1]}].");
                    $this->skipBracedBlock(1);
                } else {
                    $this->parseState($m[1], $spanStart);
                }
                continue;
            }
            $this->addWarning('Ignored unrecognized content in the state machine: [' . $this->snippet() . ']');
            $this->resync();
        }
    }

    private function parseState(string $name, int $spanStart): void {
        $state = ['events' => [], 'afters' => [], 'auto' => null, 'notes' => [], 'start' => $spanStart];
        $last = -1;
        while ($this->pos < $this->len) {
            if ($this->pos === $last) {
                $this->pos++;
                continue;
            }
            $last = $this->pos;
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->addError("The file ended inside state [$name] (missing }).");
                break;
            }
            if ($this->match('/\G\}/'))
                break;
            if ($this->match('/\Gentry\s*\/\s*\{/')) {
                $this->parseEntryBlock($name, $state);
                continue;
            }
            if ($this->match('/\G(?:exit|do)\s*\//')) {
                $this->addError("State [$name] uses an exit/do activity, which is not supported by the importer.");
                $this->resync();
                continue;
            }
            if ($this->match('/\Gafter\s*\(([^)\n]*)\)\s*->\s*([A-Za-z_]\w*)\s*;/', $m)) {
                $timer = trim($m[1]);
                if (!preg_match('/^[A-Za-z_]\w*$/', $timer)) {
                    $this->addError("State [$name]: after($timer) must reference a single attribute name; expressions are not supported.");
                } else {
                    $state['afters'][] = ['timer' => $timer, 'to' => $m[2]];
                }
                continue;
            }
            if ($this->match('/\G[A-Za-z_]\w*\s*\[/')) {
                $this->addError("State [$name] uses a guarded transition [ ... ], which is not supported by the importer.");
                $this->resync();
                continue;
            }
            if ($this->match('/\G([A-Za-z_]\w*)\s*->\s*([A-Za-z_]\w*)\s*;/', $m)) {
                foreach ($state['events'] as $existing) {
                    if ($existing['event'] === $m[1]) {
                        $this->addWarning("State [$name] lists event [{$m[1]}] more than once — only the first transition is kept.");
                        continue 2;
                    }
                }
                $state['events'][] = ['event' => $m[1], 'to' => $m[2]];
                continue;
            }
            if ($this->match('/\G->\s*([A-Za-z_]\w*)\s*;/', $m)) {
                $this->setAuto($name, $state, $m[1]);
                continue;
            }
            if ($this->match('/\G[A-Za-z_]\w*\s*\{/')) {
                $this->addError("State [$name] contains a nested state, which is not supported by the importer.");
                $this->skipBracedBlock(1);
                continue;
            }
            $this->addWarning("Ignored unrecognized content in state [$name]: [" . $this->snippet() . ']');
            $this->resync();
        }
        $state['end'] = $this->pos;
        $this->states[$name] = $state;
    }

    /**
     * Consumes the body of an `entry / { ... }` block whose opening brace was
     * already matched. Depth- and quote-aware, so braces inside nested code or
     * string literals do not end the block early.
     */
    private function parseEntryBlock(string $name, array &$state): void {
        $depth = 1;
        $code = '';
        while ($this->pos < $this->len && $depth > 0) {
            if ($depth === 1 && $this->match('/\G\s*+stateNotify\s*+\([^)\n]*+\)\s*+;/'))
                continue; // the engine logs state entry natively
            $ch = $this->src[$this->pos];
            if ($ch === '"' || $ch === "'") {
                $code .= $this->consumeString();
            } else if ($ch === '{') {
                $depth++;
                $code .= $ch;
                $this->pos++;
            } else if ($ch === '}') {
                $depth--;
                $this->pos++;
                if ($depth > 0)
                    $code .= $ch;
            } else {
                $code .= $ch;
                $this->pos++;
            }
        }
        if ($depth > 0) {
            $this->addError("The file ended inside the entry block of state [$name] (missing }).");
            return;
        }
        $code = trim(preg_replace('/\s+/', ' ', $code));
        if ($code !== '') {
            $state['notes'][] = $code;
            $snippet = strlen($code) > 80 ? substr($code, 0, 80) . '...' : $code;
            $this->addWarning("State [$name]: entry code [$snippet] has no engine equivalent — kept in the state description instead.");
        }
        if ($this->match('/\G\s*->\s*([A-Za-z_]\w*)\s*;/', $m))
            $this->setAuto($name, $state, $m[1]);
        else
            $this->match('/\G\s*;/');
    }

    private function setAuto(string $name, array &$state, string $target): void {
        if (!is_null($state['auto']))
            $this->addError("State [$name] has more than one automatic transition ({$state['auto']} and $target).");
        else
            $state['auto'] = $target;
    }

    private function validateModel(): void {
        foreach ($this->states as $name => $state) {
            $targets = array_merge(
                array_column($state['events'], 'to'),
                array_column($state['afters'], 'to'),
                is_null($state['auto']) ? [] : [$state['auto']]
            );
            foreach (array_unique($targets) as $target)
                if (!isset($this->states[$target]))
                    $this->addError("State [$name] has a transition to [$target], which is not a state in this file.");
            foreach ($state['afters'] as $after)
                if (str_starts_with($after['timer'], '__'))
                    $this->addError("State [$name]: timer name [{$after['timer']}] is reserved — names starting with __ are used internally by the engine.");
        }
        // A chain of automatic transitions that loops would recurse forever in
        // the engine at runtime, and nothing downstream detects it
        $reported = [];
        foreach ($this->states as $name => $state) {
            if (is_null($state['auto']) || isset($reported[$name]))
                continue;
            $seen = [];
            $current = $name;
            while (isset($this->states[$current]) && !is_null($this->states[$current]['auto'])) {
                if (isset($seen[$current])) {
                    foreach (array_keys($seen) as $member)
                        $reported[$member] = true;
                    $this->addError('States [' . implode(' -> ', array_keys($seen)) . " -> $current] form a loop of automatic transitions, which would run forever.");
                    break;
                }
                $seen[$current] = true;
                $current = $this->states[$current]['auto'];
            }
        }
    }

    private function buildDefinition(): ?array {
        if (count($this->states) === 0)
            return null;

        $timers = [];
        foreach ($this->states as $state)
            foreach ($state['afters'] as $after)
                if (!in_array($after['timer'], $timers))
                    $timers[] = $after['timer'];

        $deadlines = [];
        $zeroTimers = [];
        $importedTimers = [];
        foreach ($timers as $timer) {
            $init = trim($this->attributes[$timer] ?? '');
            if (!array_key_exists($timer, $this->attributes)) {
                $this->addWarning("Timer [$timer] is used in after(...) but never declared as an attribute — its deadline defaults to 0 seconds.");
                $deadlines[$timer] = 0;
            } else if (preg_match('/^\d+$/', $init)) {
                $deadlines[$timer] = intval($init);
            } else {
                if ($init !== '')
                    $this->addWarning("Timer [$timer]: initializer [$init] is not a plain number — imported as 0 seconds.");
                $deadlines[$timer] = 0;
            }
            if ($deadlines[$timer] === 0)
                $zeroTimers[] = $timer;
            else
                $importedTimers[] = $timer . '=' . $deadlines[$timer] . 's';
        }
        if (count($zeroTimers) > 0)
            $this->addWarning('Timer(s) [' . implode(', ', $zeroTimers) . '] were imported with 0-second deadlines. Set each one before publishing: open the state that arms it (States tab), expand Entry Actions, and edit the setTimer action\'s Deadline.');
        if (count($importedTimers) > 0)
            $this->addWarning('Timer deadline(s) imported from attribute initializers: ' . implode(', ', $importedTimers) . ' — review before publishing.');
        $unused = array_diff(array_keys($this->attributes), $timers);
        if (count($unused) > 0)
            $this->addWarning('Attribute(s) [' . implode(', ', $unused) . '] are never used in after(...) and were not imported.');

        $events = [];
        $states = [];
        foreach ($this->states as $name => $state) {
            $transitions = [];
            $manualMoves = [];
            $entry = [];
            foreach ($state['events'] as $t) {
                $transitions[] = ['event' => $t['event'], 'to' => $t['to']];
                // "auto" is reserved in the engine's manual-move vocabulary and
                // would silently no-op, so an event with that name gets no move
                if ($t['event'] === 'auto')
                    $this->addWarning("State [$name]: event [auto] collides with a reserved engine keyword — participants cannot be moved through it manually.");
                else
                    $manualMoves[] = ['to' => $t['to'], 'via' => $t['event']];
                $events[$t['event']] = true;
            }
            foreach ($state['afters'] as $t) {
                $transitions[] = ['after' => $t['timer'], 'to' => $t['to']];
                $manualMoves[] = ['to' => $t['to'], 'via' => 'timeout:' . $t['timer']];
                $entry[] = [
                    'type'     => 'setTimer',
                    'timer'    => $t['timer'],
                    'deadline' => ['type' => 'fixedSeconds', 'seconds' => $deadlines[$t['timer']]]
                ];
            }
            if (!is_null($state['auto']))
                $transitions[] = ['auto' => true, 'to' => $state['auto']];

            $built = [];
            $description = trim(implode("\n", array_merge($this->commentsIn($state['start'], $state['end']), $state['notes'])));
            if ($description !== '')
                $built['description'] = $description;
            if (count($transitions) === 0)
                $built['terminal'] = true;
            if (count($entry) > 0)
                $built['entry'] = $entry;
            $built['transitions'] = $transitions;
            $built['manualMoves'] = $manualMoves;
            $states[$name] = $built;
        }

        $settings = [];
        if (isset($events['receivedEndProtocol']))
            $settings['endProtocolEvent'] = 'receivedEndProtocol';
        $settings['collection'] = 'none';

        return [
            'schemaVersion' => 1,
            'name'          => $this->className,
            'initialState'  => array_key_first($this->states),
            'timers'        => $timers,
            'settings'      => $settings,
            'eventMappings' => ['sms' => ['defaultAction' => 'ignore']],
            'states'        => $states,
            'surveys'       => []
        ];
    }

    private function commentsIn(int $start, int $end): array {
        $found = [];
        foreach ($this->comments as $offset => $text)
            if ($offset >= $start && $offset < $end)
                $found[] = $text;
        return $found;
    }

    private function addWarning(string $message): void {
        if (count($this->warnings) < self::MAX_MESSAGES)
            $this->warnings[] = $message;
        else if (count($this->warnings) === self::MAX_MESSAGES)
            $this->warnings[] = 'Further warnings were suppressed.';
    }

    private function addError(string $message): void {
        if (count($this->errors) < self::MAX_MESSAGES)
            $this->errors[] = $message;
        else if (count($this->errors) === self::MAX_MESSAGES)
            $this->errors[] = 'Further errors were suppressed.';
    }

    private function match(string $pattern, ?array &$m = null): bool {
        if (preg_match($pattern, $this->src, $m, 0, $this->pos) !== 1)
            return false;
        $this->pos += strlen($m[0]);
        return true;
    }

    private function skipWhitespace(): void {
        while ($this->pos < $this->len && ctype_space($this->src[$this->pos]))
            $this->pos++;
    }

    /** Consumes a quoted string literal (with backslash escapes) and returns it. */
    private function consumeString(): string {
        $quote = $this->src[$this->pos];
        $out = $quote;
        $this->pos++;
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            $out .= $ch;
            $this->pos++;
            if ($ch === '\\' && $this->pos < $this->len) {
                $out .= $this->src[$this->pos];
                $this->pos++;
            } else if ($ch === $quote || $ch === "\n") {
                break;
            }
        }
        return $out;
    }

    private function skipBracedBlock(int $depth): void {
        while ($this->pos < $this->len && $depth > 0) {
            $ch = $this->src[$this->pos];
            if ($ch === '"' || $ch === "'") {
                $this->consumeString();
                continue;
            }
            if ($ch === '{')
                $depth++;
            else if ($ch === '}')
                $depth--;
            $this->pos++;
        }
    }

    /** Advances past the next ; or to the next line/closing brace so parsing can continue. */
    private function resync(): void {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ';') {
                $this->pos++;
                return;
            }
            if ($ch === "\n" || $ch === '}')
                return;
            $this->pos++;
        }
    }

    private function snippet(): string {
        $raw = substr($this->src, $this->pos, 40);
        $line = trim(strtok($raw, "\n"));
        return $line === '' ? trim($raw) : $line;
    }
}
