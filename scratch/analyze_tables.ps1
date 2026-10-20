$content = [System.IO.File]::ReadAllText('d:\laragon\www\oqc\modules\inspection\index.php')
$pattern = '(?i)\b(?:from|join)\s+([a-zA-Z0-9_]+)\b'
$regex = [System.Text.RegularExpressions.Regex]::new($pattern)
$matches = $regex.Matches($content)
$tables = [System.Collections.Generic.HashSet[string]]::new()
foreach ($m in $matches) {
    [void]$tables.Add($m.Groups[1].Value.ToLower())
}
$sorted = [System.Linq.Enumerable]::ToArray($tables)
[System.Array]::Sort($sorted)
$sorted | Out-File 'd:\laragon\www\oqc\scratch\tables_found.txt'
