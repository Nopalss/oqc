$files = Get-ChildItem -Path "C:\Users\user\.gemini\antigravity-ide\brain\*\.system_generated\logs\transcript_full.jsonl"
foreach ($f in $files) {
    $stream = [System.IO.File]::OpenRead($f.FullName)
    $reader = New-Object System.IO.StreamReader($stream)
    $idx = 0
    while (($line = $reader.ReadLine()) -ne $null) {
        if ($line.Contains('Langkah 1 dari 2') -or $line.Contains('PENCAPAIAN SAYA HARI INI') -or $line.Contains('wizard-step-tag')) {
            Write-Output "Found in $($f.FullName) line $idx"
            [System.IO.File]::WriteAllText("d:\laragon\www\oqc\scratch\found_snippet_$idx.txt", $line.Substring(0, [Math]::Min(1000, $line.Length)), [System.Text.Encoding]::UTF8)
            break
        }
        $idx++
    }
    $reader.Close()
    $stream.Close()
}
