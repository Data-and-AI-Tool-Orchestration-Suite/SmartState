param(
    [string]$ProtocolDir = (Join-Path $PSScriptRoot "..\..\..\..\java\src\main\resources\protocols\fasting"),
    [string]$OutFile = (Join-Path $PSScriptRoot "02a_definitions.sql")
)

$ErrorActionPreference = "Stop"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

$files = Get-ChildItem -Path $ProtocolDir -Filter "*.json" | Sort-Object Name
if ($files.Count -eq 0) { throw "No protocol JSON files found in $ProtocolDir" }

$sb = New-Object System.Text.StringBuilder
[void]$sb.AppendLine('\echo Loading fasting protocol definitions into staging')
[void]$sb.AppendLine('')
[void]$sb.AppendLine('CREATE TABLE IF NOT EXISTS fasting_staging.protocol_defs(')
[void]$sb.AppendLine('    file_name TEXT PRIMARY KEY,')
[void]$sb.AppendLine('    def_name TEXT NOT NULL,')
[void]$sb.AppendLine('    definition JSONB NOT NULL')
[void]$sb.AppendLine(');')
[void]$sb.AppendLine('')
[void]$sb.AppendLine('DELETE FROM fasting_staging.protocol_defs;')
[void]$sb.AppendLine('')

foreach ($f in $files) {
    $raw = [System.IO.File]::ReadAllText($f.FullName, [System.Text.Encoding]::UTF8)
    $parsed = $raw | ConvertFrom-Json
    $name = $parsed.name
    if ([string]::IsNullOrWhiteSpace($name)) { throw "Missing name field in $($f.Name)" }
    $tag = 'ssdef'
    while ($raw.Contains("`$$tag`$")) { $tag = $tag + "x" }
    [void]$sb.AppendLine("INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES")
    [void]$sb.AppendLine("('$($f.Name)', '$name', `$$tag`$")
    [void]$sb.AppendLine($raw.TrimEnd())
    [void]$sb.AppendLine("`$$tag`$::jsonb);")
    [void]$sb.AppendLine('')
    Write-Host "Added $($f.Name) as '$name'"
}

[void]$sb.AppendLine('SELECT file_name, def_name FROM fasting_staging.protocol_defs ORDER BY file_name;')
[System.IO.File]::WriteAllText($OutFile, $sb.ToString(), $utf8NoBom)
Write-Host "Wrote $OutFile"
