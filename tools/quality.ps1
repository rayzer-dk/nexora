[CmdletBinding()]
param(
    [ValidateSet('quality:php', 'analyse', 'cs', 'cs:psr12', 'deptrac', 'rector:check', 'check:encoding', 'check:source-guard', 'test:source-encoding', 'test:source-guard')]
    [string]$Task = 'quality:php',
    [string]$PhpBinary = '',
    [string]$ComposerPhar = ''
)

$ErrorActionPreference = 'Stop'
$repository = Split-Path -Parent $PSScriptRoot
$laragonRoot = if ($env:LARAGON_ROOT) { $env:LARAGON_ROOT } else { 'C:\laragon' }

if (-not $PhpBinary) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $PhpBinary = $phpCommand.Source }
    else {
        $phpCandidates = Get-ChildItem -Path (Join-Path $laragonRoot 'bin\php\php-8.4*\php.exe') -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending
        if ($phpCandidates) { $PhpBinary = ($phpCandidates | Select-Object -First 1).FullName }
    }
}
if (-not $PhpBinary -or -not (Test-Path -LiteralPath $PhpBinary -PathType Leaf)) {
    throw 'PHP 8.4+ was not found. Supply -PhpBinary or add PHP to PATH.'
}
$phpVersion = & $PhpBinary -r 'echo PHP_VERSION_ID;'
if ($LASTEXITCODE -ne 0 -or [int]$phpVersion -lt 80400 -or [int]$phpVersion -ge 90000) {
    throw 'Nexora development tools require PHP >= 8.4 and < 9.0.'
}
if (-not $ComposerPhar) { $ComposerPhar = Join-Path $laragonRoot 'bin\composer\composer.phar' }
$composerCommand = Get-Command composer -ErrorAction SilentlyContinue
if (-not (Test-Path -LiteralPath $ComposerPhar -PathType Leaf) -and -not $composerCommand) {
    throw 'Composer was not found. Supply -ComposerPhar or add Composer to PATH.'
}

$previousLocation = Get-Location
$previousPath = $env:PATH
try {
    Set-Location -LiteralPath $repository
    $env:PATH = (Split-Path -Parent $PhpBinary) + [IO.Path]::PathSeparator + $previousPath
    if (Test-Path -LiteralPath $ComposerPhar -PathType Leaf) { & $PhpBinary $ComposerPhar run-script $Task }
    else { & $composerCommand.Source run-script $Task }
    $result = $LASTEXITCODE
}
finally {
    Set-Location -LiteralPath $previousLocation.Path
    $env:PATH = $previousPath
}
exit $result
