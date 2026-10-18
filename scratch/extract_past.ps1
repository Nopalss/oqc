$filePath = "C:\Users\user\.gemini\antigravity-ide\brain\61a9a496-0f62-490e-ac86-f67c26d7966c\.system_generated\logs\transcript_full.jsonl"
$stream = [System.IO.File]::OpenRead($filePath)
$reader = New-Object System.IO.StreamReader($stream)
$idx = 0
while (($line = $reader.ReadLine()) -ne $null) {
    if ($idx -eq 450) {
        $obj = $line | ConvertFrom-Json
        if ($obj.tool_calls) {
            $tc = $obj.tool_calls[0]
            Write-Host "Tool name: $($tc.name)"
            Write-Host "TargetFile: $($tc.args.TargetFile)"
            Write-Host "StartLine: $($tc.args.StartLine)"
            Write-Host "EndLine: $($tc.args.EndLine)"
            if ($tc.args.ReplacementContent) {
                [System.IO.File]::WriteAllText('d:\laragon\www\oqc\scratch\recovered_edit.txt', $tc.args.ReplacementContent, [System.Text.Encoding]::UTF8)
                Write-Host "Saved ReplacementContent!"
            } elseif ($tc.args.CodeContent) {
                [System.IO.File]::WriteAllText('d:\laragon\www\oqc\scratch\recovered_edit.txt', $tc.args.CodeContent, [System.Text.Encoding]::UTF8)
                Write-Host "Saved CodeContent!"
            }
        } elseif ($obj.content) {
            [System.IO.File]::WriteAllText('d:\laragon\www\oqc\scratch\recovered_edit.txt', $obj.content, [System.Text.Encoding]::UTF8)
            Write-Host "Saved content!"
        }
        break
    }
    $idx++
}
$reader.Close()
$stream.Close()
