param(
    [string]$OutputDir = "",
    [string]$ZipName = ""
)

$ErrorActionPreference = "Stop"

$repoRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($repoRoot)) {
    $repoRoot = (Get-Location).Path
}

$versionFile = Join-Path $repoRoot "version.php"
if (-not (Test-Path $versionFile)) {
    throw "version.php not found at $versionFile"
}

$versionPhp = Get-Content $versionFile -Raw
$component = ""
$release = ""
if ($versionPhp -match "\$plugin->component\s*=\s*'([^']+)'") {
    $component = $Matches[1]
}
if ($versionPhp -match "\$plugin->release\s*=\s*'([^']+)'") {
    $release = $Matches[1]
}

if ([string]::IsNullOrWhiteSpace($component)) {
    throw "Unable to resolve `$plugin->component from version.php"
}

$parts = $component.Split('_', 2)
if ($parts.Count -ne 2 -or [string]::IsNullOrWhiteSpace($parts[1])) {
    throw "Unexpected Moodle component format: $component"
}

$pluginDirName = $parts[1]

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $repoRoot "dist"
}
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

if ([string]::IsNullOrWhiteSpace($ZipName)) {
    $suffix = if ([string]::IsNullOrWhiteSpace($release)) { "package" } else { $release }
    $ZipName = "$component-$suffix.zip"
}

$zipPath = Join-Path $OutputDir $ZipName
if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("moodle-plugin-package-" + [guid]::NewGuid().ToString("N"))
$stagingPluginRoot = Join-Path $tempRoot $pluginDirName
New-Item -ItemType Directory -Force -Path $stagingPluginRoot | Out-Null

$excludeDirectoryNames = @(
    ".git",
    ".github",
    "dist",
    "node_modules"
)

$excludeFileNames = @(
    ".gitignore",
    ".gitattributes",
    ".gitmodules",
    "build-plugin-zip.ps1"
)

try {
    Get-ChildItem -LiteralPath $repoRoot -Force | ForEach-Object {
        if ($_.Name -in $excludeDirectoryNames) {
            return
        }
        if (-not $_.PSIsContainer -and $_.Name -in $excludeFileNames) {
            return
        }

        $destination = Join-Path $stagingPluginRoot $_.Name
        Copy-Item -LiteralPath $_.FullName -Destination $destination -Recurse -Force
    }

    Get-ChildItem -LiteralPath $stagingPluginRoot -Recurse -Force | Where-Object {
        $_.PSIsContainer -and $_.Name -in $excludeDirectoryNames
    } | Remove-Item -Recurse -Force

    Get-ChildItem -LiteralPath $stagingPluginRoot -Recurse -Force | Where-Object {
        -not $_.PSIsContainer -and $_.Name -in $excludeFileNames
    } | Remove-Item -Force

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $archive = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $stagingPluginRoot -Recurse -File | ForEach-Object {
            $relativePath = $_.FullName.Substring($tempRoot.Length).TrimStart('\', '/')
            $entryName = $relativePath -replace '\\', '/'
            $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            $entryStream = $entry.Open()
            $fileStream = [System.IO.File]::OpenRead($_.FullName)
            try {
                $fileStream.CopyTo($entryStream)
            }
            finally {
                $fileStream.Dispose()
                $entryStream.Dispose()
            }
        }
    }
    finally {
        $archive.Dispose()
    }

    Write-Output "Created Moodle plugin package: $zipPath"
}
finally {
    if (Test-Path $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
