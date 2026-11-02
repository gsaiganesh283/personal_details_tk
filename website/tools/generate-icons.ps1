<#
Generates PWA/app icons for website/assets/icons/ from the existing app
artwork (website/assets/Gemini_Generated_Image_dzgiqpdzgiqpdzgi.png).

The source PNG has the rounded-square glyph centered with a plain margin
around it -- this script auto-detects that margin (by downsampling and
scanning for the background color) and crops it out before resizing, so
icons don't end up floating small inside a padded square.

One-off / re-runnable: run whenever the source artwork changes.
    powershell -File website\tools\generate-icons.ps1
#>

Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$srcPath = Join-Path $root "assets\Gemini_Generated_Image_dzgiqpdzgiqpdzgi.png"
$outDir = Join-Path $root "assets\icons"
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

# App's dark background color (app/core/theme.py DARK["bg"]) -- used as the
# canvas fill for maskable icons so Android's adaptive-icon mask has safe
# bleed room instead of clipping the glyph.
$bgColor = [System.Drawing.Color]::FromArgb(255, 27, 28, 34) # #1b1c22

function Get-ContentBoundingBox([System.Drawing.Bitmap]$bmp) {
    # Downsample to 256x256 first so the pixel scan is fast, then scale the
    # detected box back up to source-image coordinates. The source has a
    # smooth near-white gradient background (not a flat color), so instead
    # of comparing to one sampled background pixel, flag a pixel as "content"
    # by lightness/saturation: the background is light and low-saturation
    # everywhere; the purple/blue emblem is neither.
    $sample = 256
    $thumb = New-Object System.Drawing.Bitmap $sample, $sample
    $g = [System.Drawing.Graphics]::FromImage($thumb)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.DrawImage($bmp, 0, 0, $sample, $sample)
    $g.Dispose()

    $lightnessThreshold = 215  # avg channel above this = "light"
    $satThreshold = 25         # max-min channel spread below this = "low saturation"
    $minX = $sample; $maxX = 0; $minY = $sample; $maxY = 0
    $found = $false

    for ($y = 0; $y -lt $sample; $y++) {
        for ($x = 0; $x -lt $sample; $x++) {
            $p = $thumb.GetPixel($x, $y)
            $avg = ($p.R + $p.G + $p.B) / 3
            $sat = ([Math]::Max($p.R, [Math]::Max($p.G, $p.B))) - ([Math]::Min($p.R, [Math]::Min($p.G, $p.B)))
            $isBackground = ($avg -gt $lightnessThreshold) -and ($sat -lt $satThreshold)
            if (-not $isBackground) {
                $found = $true
                if ($x -lt $minX) { $minX = $x }
                if ($x -gt $maxX) { $maxX = $x }
                if ($y -lt $minY) { $minY = $y }
                if ($y -gt $maxY) { $maxY = $y }
            }
        }
    }
    $thumb.Dispose()

    if (-not $found) {
        return New-Object System.Drawing.Rectangle 0, 0, $bmp.Width, $bmp.Height
    }

    $scale = $bmp.Width / $sample
    $pad = 2 # a little slack in sample-space for anti-aliased edges
    $sx = [Math]::Max(0, ($minX - $pad) * $scale)
    $sy = [Math]::Max(0, ($minY - $pad) * $scale)
    $ex = [Math]::Min($bmp.Width, ($maxX + $pad) * $scale)
    $ey = [Math]::Min($bmp.Height, ($maxY + $pad) * $scale)

    # Force a square crop (the source glyph is a rounded square) centered on
    # the detected box, so resizing to square targets never distorts it.
    $w = $ex - $sx
    $h = $ey - $sy
    $side = [Math]::Max($w, $h)
    $cx = $sx + $w / 2
    $cy = $sy + $h / 2
    $sx = [Math]::Max(0, $cx - $side / 2)
    $sy = [Math]::Max(0, $cy - $side / 2)
    $side = [Math]::Min($side, [Math]::Min($bmp.Width - $sx, $bmp.Height - $sy))

    return New-Object System.Drawing.Rectangle ([int]$sx), ([int]$sy), ([int]$side), ([int]$side)
}

function Save-Resized([System.Drawing.Bitmap]$src, [System.Drawing.Rectangle]$cropRect, [int]$size, [string]$outPath) {
    $canvas = New-Object System.Drawing.Bitmap $size, $size
    $g = [System.Drawing.Graphics]::FromImage($canvas)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $destRect = New-Object System.Drawing.Rectangle 0, 0, $size, $size
    $g.DrawImage($src, $destRect, $cropRect, [System.Drawing.GraphicsUnit]::Pixel)
    $g.Dispose()
    $canvas.Save($outPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $canvas.Dispose()
}

function Save-Maskable([System.Drawing.Bitmap]$src, [System.Drawing.Rectangle]$cropRect, [int]$size, [string]$outPath, [System.Drawing.Color]$bg) {
    $canvas = New-Object System.Drawing.Bitmap $size, $size
    $g = [System.Drawing.Graphics]::FromImage($canvas)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.Clear($bg)
    # Draw the glyph at ~68% of the canvas, centered -- leaves safe-zone
    # bleed for Android's adaptive-icon circle/squircle mask.
    $inner = [int]($size * 0.68)
    $offset = [int](($size - $inner) / 2)
    $destRect = New-Object System.Drawing.Rectangle $offset, $offset, $inner, $inner
    $g.DrawImage($src, $destRect, $cropRect, [System.Drawing.GraphicsUnit]::Pixel)
    $g.Dispose()
    $canvas.Save($outPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $canvas.Dispose()
}

if (-not (Test-Path $srcPath)) {
    throw "Source artwork not found: $srcPath"
}

$src = [System.Drawing.Bitmap]::FromFile($srcPath)

# "any"-purpose icons use the full source art as-is -- its own light
# background already fills the canvas edge-to-edge and looks intentional.
$fullRect = New-Object System.Drawing.Rectangle 0, 0, $src.Width, $src.Height

# Maskable icons need just the emblem (tightly cropped) so it can be reset
# on the app's own dark background with proper safe-zone bleed -- using the
# full padded art here would float a small white square inside a dark one.
$emblemCrop = Get-ContentBoundingBox $src
Write-Output "Detected emblem crop: X=$($emblemCrop.X) Y=$($emblemCrop.Y) W=$($emblemCrop.Width) H=$($emblemCrop.Height) (source $($src.Width)x$($src.Height))"

Save-Resized $src $fullRect 192 (Join-Path $outDir "icon-192.png")
Save-Resized $src $fullRect 512 (Join-Path $outDir "icon-512.png")
Save-Resized $src $fullRect 180 (Join-Path $outDir "apple-touch-icon-180.png")
Save-Resized $src $fullRect 96  (Join-Path $outDir "icon-96.png")
Save-Resized $src $fullRect 32  (Join-Path $outDir "favicon-32.png")
Save-Resized $src $fullRect 16  (Join-Path $outDir "favicon-16.png")
Save-Maskable $src $emblemCrop 192 (Join-Path $outDir "icon-maskable-192.png") $bgColor
Save-Maskable $src $emblemCrop 512 (Join-Path $outDir "icon-maskable-512.png") $bgColor

$src.Dispose()
Write-Output "Icons written to $outDir"
Get-ChildItem $outDir | Select-Object Name, Length
