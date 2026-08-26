New-Item -ItemType Directory -Force -Path 'assets/icons' | Out-Null
Add-Type -AssemblyName System.Drawing

function Create-PwaIcon($srcPath, $destPath, $size) {
    $srcImage = [System.Drawing.Image]::FromFile($srcPath)
    $bitmap = New-Object System.Drawing.Bitmap($size, $size)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality

    $brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
    $graphics.FillRectangle($brush, 0, 0, $size, $size)

    $pad = [int]($size * 0.08)
    $targetW = $size - ($pad * 2)
    $targetH = $size - ($pad * 2)

    $ratioSrc = $srcImage.Width / $srcImage.Height
    $ratioTarget = $targetW / $targetH

    if ($ratioSrc -gt $ratioTarget) {
        $destW = $targetW
        $destH = [int]($targetW / $ratioSrc)
    } else {
        $destH = $targetH
        $destW = [int]($targetH * $ratioSrc)
    }

    $destX = [int](($size - $destW) / 2)
    $destY = [int](($size - $destH) / 2)

    $graphics.DrawImage($srcImage, $destX, $destY, $destW, $destH)

    $bitmap.Save($destPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $graphics.Dispose()
    $bitmap.Dispose()
    $srcImage.Dispose()
    Write-Host "Created: $destPath ($size x $size)"
}

Create-PwaIcon 'assets/images/bpc_logo.png' 'assets/icons/icon-192.png' 192
Create-PwaIcon 'assets/images/bpc_logo.png' 'assets/icons/icon-512.png' 512
Create-PwaIcon 'assets/images/bpc_logo.png' 'assets/icons/icon-180.png' 180

