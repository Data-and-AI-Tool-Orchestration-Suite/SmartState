(function() {
    function escDot(value) {
        return String(value == null ? '' : value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function definitionToDot(def, currentState) {
        var lines = [];
        lines.push('digraph protocol {');
        lines.push('  rankdir=TB;');
        lines.push('  bgcolor="transparent";');
        lines.push('  node [shape=box, style="rounded,filled", fillcolor="#ffffff", color="#343a40", fontname="Helvetica", fontsize=12];');
        lines.push('  edge [color="#6c757d", fontname="Helvetica", fontsize=10];');
        var states = (def && def.states) ? def.states : {};
        Object.keys(states).forEach(function(name) {
            var state = states[name] || {};
            var attrs = [];
            if (state.terminal) {
                attrs.push('peripheries=2');
            }
            if (currentState && name === currentState) {
                attrs.push('fillcolor="#62dfff"');
            }
            var line = '  "' + escDot(name) + '"';
            if (attrs.length > 0) {
                line += ' [' + attrs.join(', ') + ']';
            }
            lines.push(line + ';');
        });
        Object.keys(states).forEach(function(name) {
            var transitions = (states[name] && states[name].transitions) || [];
            transitions.forEach(function(transition) {
                if (!transition || !transition.to) {
                    return;
                }
                var label = 'auto';
                if (transition.event) {
                    label = transition.event;
                } else if (transition.after) {
                    label = 'after(' + transition.after + ')';
                }
                lines.push('  "' + escDot(name) + '" -> "' + escDot(transition.to) + '" [label="' + escDot(label) + '"];');
            });
        });
        lines.push('}');
        return lines.join('\n');
    }

    function renderProtocolGraph(el, dot) {
        var hpcc = window["@hpcc-js/wasm"];
        if (!hpcc || !hpcc.Graphviz) {
            return;
        }
        hpcc.Graphviz.load().then(function(graphviz) {
            var target = typeof el === 'string' ? document.querySelector(el) : el;
            if (!target) {
                return;
            }
            target.innerHTML = graphviz.layout(dot, "svg", "dot");
            var svg = target.querySelector('svg');
            if (svg) {
                svg.removeAttribute('width');
                svg.removeAttribute('height');
                svg.style.maxWidth = '100%';
                svg.style.maxHeight = '55vh';
                svg.style.height = 'auto';
                svg.style.display = 'block';
                svg.style.margin = '0 auto';
            }
        }).catch(function(e) {
            console.error(e);
        });
    }

    window.definitionToDot = definitionToDot;
    window.renderProtocolGraph = renderProtocolGraph;
})();
