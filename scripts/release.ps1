<#
.SYNOPSIS
  Deterministic packaging helper for Atomic LinkedIn Feed releases.

.DESCRIPTION
  - Validates a semantic version argument.
  - Verifies that every canonical version reference matches the requested version.
  - Inspects Git state (dirty worktree, tag existence) without mutating it.
  - Builds dist/atomic-wp-linkedin-feed-v{VERSION}.zip using `git archive`
    against a user-supplied git-ref (typically a tag like v0.11.0).
  - Prints a SHA-256 checksum for the produced ZIP.

  The script never:
    - resets the working tree
    - stashes user changes
    - creates or pushes tags / commits
    - uploads to GitHub

.EXAMPLE
  PS> ./scripts/release.ps1 -Version 0.11.0 -GitRef v0.11.0
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$')]
    [string]$Version,

    [Parameter(Mandatory)]
    [string]$GitRef
)

$ErrorActionPreference = 'Stop'
$PluginDir = Split-Path -Parent $PSScriptRoot
$ZipName   = "atomic-wp-linkedin-feed-v$Version.zip"
$DistDir   = Join-Path $PluginDir 'dist'
$ZipPath   = Join-Path $DistDir $ZipName

Write-Host "== Release helper for Atomic LinkedIn Feed $Version ==" -ForegroundColor Cyan
Write-Host "   Plugin dir : $PluginDir"
Write-Host "   Git ref    : $GitRef"
Write-Host "   Output ZIP : $ZipPath"
Write-Host ''

# --- 1. Validate Git ref -----------------------------------------------------
try {
    git --git-dir (Join-Path $PluginDir '.git') rev-parse --verify --quiet "$GitRef^{commit}" | Out-Null
} catch {
    throw "Git ref '$GitRef' was not found locally. Create or fetch the tag/commit first."
}
Write-Host '[PASS] Git ref exists:' $GitRef

# --- 2. Reject dirty worktree -----------------------------------------------
$gitStatus = & git -C $PluginDir status --porcelain
if ($LASTEXITCODE -ne 0) { throw 'git status failed' }
$dirty = @($gitStatus | Where-Object { $_ -notmatch '^\?\? \.trae/' -and $_ -notmatch '^ D dist/' })
if ($dirty.Count -gt 0) {
    Write-Warning 'Dirty working tree (non-.trae / non-dist entries):'
    $dirty | ForEach-Object { Write-Warning ('  ' + $_) }
    throw 'Cowardly refusing to package a dirty worktree. Stash or commit first.'
}
Write-Host '[PASS] Worktree is clean (ignoring .trae/ and dist/)'

# --- 3. Canonical version reference audit -----------------------------------
function Assert-VersionMatch($label, [string]$filePath, [regex]$pattern, [string]$expected) {
    if (-not (Test-Path $filePath)) { throw "Missing file: $filePath" }
    $content = Get-Content -Raw -LiteralPath $filePath
    $m = $pattern.Match($content)
    if (-not $m.Success) { throw "Could not locate version in $label ($filePath)" }
    $actual = $m.Groups[1].Value
    if ($actual -ne $expected) {
        throw "MISMATCH $label : expected=$expected actual=$actual ($filePath)"
    }
    Write-Host ("[PASS] {0,-24} = {1}" -f $label, $actual)
}

$mainFile    = Join-Path $PluginDir 'atomic-wp-linkedin-feed.php'
$composer    = Join-Path $PluginDir 'composer.json'
$blockJson   = Join-Path $PluginDir 'blocks' 'feed' 'block.json'
$readmeTxt   = Join-Path $PluginDir 'readme.txt'
$readmeMd    = Join-Path $PluginDir 'README.md'

Assert-VersionMatch 'Plugin header Version'   $mainFile  ([regex]'(?m)^ \* Version:\s*([^\r\n]+)') $Version
Assert-VersionMatch 'ATOMIC_WP_SOCIAL_SYNC_VERSION' $mainFile ([regex]"define\(\s*'ATOMIC_WP_SOCIAL_SYNC_VERSION',\s*'([^']+)'") $Version
Assert-VersionMatch 'composer.json version'   $composer  ([regex]'"version"\s*:\s*"([^"]+)"')      $Version
Assert-VersionMatch 'block.json version'      $blockJson ([regex]'"version"\s*:\s*"([^"]+)"')      $Version
Assert-VersionMatch 'readme.txt Stable tag'   $readmeTxt ([regex]'(?m)^Stable tag:\s*([^\r\n]+)')  $Version
if ($readmeMd -ne $null -and (Test-Path $readmeMd)) {
    Assert-VersionMatch 'README.md documented' $readmeMd ([regex]'^Version\s+([0-9][^ ·]+)') $Version
}

# --- 4. Prepare dist/ and build ZIP -----------------------------------------
if (-not (Test-Path $DistDir)) { New-Item -ItemType Directory -Path $DistDir | Out-Null }
if (Test-Path $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }

Write-Host ''
Write-Host "Building ZIP from git ref $GitRef ..."
& git -C $PluginDir archive --prefix=atomic-wp-linkedin-feed/ --format=zip -o $ZipPath $GitRef
if ($LASTEXITCODE -ne 0) { throw "git archive failed (exit=$LASTEXITCODE)" }
if (-not (Test-Path $ZipPath)) { throw "git archive did not produce $ZipPath" }

$size = (Get-Item -LiteralPath $ZipPath).Length
$sha  = (Get-FileHash -Algorithm SHA256 -LiteralPath $ZipPath).Hash.ToLowerInvariant()

Write-Host ''
Write-Host '== Packaging complete ==' -ForegroundColor Green
Write-Host "  ZIP   : $ZipPath"
Write-Host "  SIZE  : $size bytes"
Write-Host "  SHA256: $sha"
