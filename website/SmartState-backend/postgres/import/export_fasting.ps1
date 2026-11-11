param(
    [Parameter(Mandatory = $true)][string]$Server,
    [string]$Database = "myDB",
    [string]$User = "sa",
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$OutDir = ".\csv"
)

$ErrorActionPreference = "Stop"
$fieldSep = [char]0x1F
$rowSep = [char]0x1E

$tables = [ordered]@{
    user_roles      = @("id", "role_name")
    users           = @("id", "linkblue", "role", "p_hash")
    protocol_types  = @("protocol_type_uuid", "study", "name")
    participants    = @("participant_uuid", "study", "participant_json")
    enrollments     = @("enrollment_uuid", "participant_uuid", "protocol_type_uuid", "status")
    messages        = @("message_uuid", "participant_uuid", "TS", "message_direction", "study", "message_json")
    state_log       = @("participant_uuid", "TS", "log_json")
    queued_messages = @("message_uuid", "participant_uuid", "toNumber", "fromNumber", "scheduledFor", "message_json", "study")
    surveys         = @("token", "participant_uuid", "created_at", "finished_at", "survey_json", "survey_name")
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$rawDir = Join-Path $OutDir "_raw"
New-Item -ItemType Directory -Force -Path $rawDir | Out-Null
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function ConvertTo-CsvField {
    param([string]$Value)
    if ($null -eq $Value -or $Value -eq "") { return "" }
    return '"' + $Value.Replace('"', '""') + '"'
}

foreach ($table in $tables.Keys) {
    $cols = $tables[$table] -join ", "
    $query = "SELECT $cols FROM [$Database].dbo.[$table]"
    $rawPath = Join-Path $rawDir "$table.dat"
    $csvPath = Join-Path $OutDir "$table.csv"

    Write-Host "Exporting $table ..."
    & bcp $query queryout $rawPath -S $Server -U $User -P $Password -w -t "$fieldSep" -r "$rowSep"
    if ($LASTEXITCODE -ne 0) { throw "bcp failed for table $table (exit $LASTEXITCODE)" }

    $text = ""
    if (Test-Path $rawPath) {
        $text = [System.IO.File]::ReadAllText($rawPath, [System.Text.Encoding]::Unicode)
    }
    $sb = New-Object System.Text.StringBuilder
    $header = ($tables[$table] | ForEach-Object { $_.ToLowerInvariant() }) -join ","
    [void]$sb.AppendLine($header)

    $rowCount = 0
    foreach ($row in $text.Split($rowSep)) {
        if ($row.Length -eq 0) { continue }
        $fields = $row.Split($fieldSep)
        $line = ($fields | ForEach-Object { ConvertTo-CsvField -Value $_ }) -join ","
        [void]$sb.Append($line)
        [void]$sb.Append("`n")
        $rowCount++
    }

    [System.IO.File]::WriteAllText($csvPath, $sb.ToString(), $utf8NoBom)
    Write-Host "  wrote $rowCount rows to $csvPath"
}

Remove-Item -Recurse -Force -Confirm:$false $rawDir
Write-Host "Export complete. CSVs are in $OutDir"
