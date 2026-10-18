$filePath = "C:\Users\user\.gemini\antigravity-ide\brain\24ed0abf-e952-4df7-8508-db8347313700\.system_generated\logs\transcript_full.jsonl"
$stream = [System.IO.File]::OpenRead($filePath)
$reader = New-Object System.IO.StreamReader($stream)
$idx = 0
while (($line = $reader.ReadLine()) -ne $null) {
    if ($line.Contains('"TargetFile"') -and $line.Contains('session.php') -and $line.Contains('TOTAL KANBAN')) {
        Write-Output "Found in line $idx"
    }
    $idx++
}
$reader.Close()
$stream.Close()
