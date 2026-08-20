# File Type Icons

This directory contains icons used by the RSpade file attachment system to visually represent different file types.

## Icon Sources and Licenses

### SVG Icons (Generic Categories)

The following SVG icons are sourced from the **Papirus Icon Theme**:

- `image.svg` - Generic image files
- `video.svg` - Generic video files
- `audio.svg` - Generic audio files
- `archive.svg` - Archive/compressed files
- `text.svg` - Plain text files
- `code.svg` - Programming/code files
- `document.svg` - Document files (Word, etc.)
- `spreadsheet.svg` - Spreadsheet files (Excel, etc.)
- `presentation.svg` - Presentation files (PowerPoint, etc.)
- `file.svg` - Generic file fallback

**Source:** https://github.com/PapirusDevelopmentTeam/papirus-icon-theme
**License:** GPL-3.0
**Copyright:** PapirusDevelopmentTeam

### Custom SVG Icons

- `3d_model.svg` - 3D model files (STL, OBJ, etc.)

**Created for RSpade** - Custom wireframe cube design
**License:** MIT (same as RSpade framework)

### PNG Icons (Brand-Specific)

The following PNG icons are sourced from **Free File Icons** by Redbooth:

- `pdf.png` - Adobe PDF files
- `psd.png` - Adobe Photoshop files
- `ai.png` - Adobe Illustrator files

**Source:** https://github.com/redbooth/free-file-icons
**License:** MIT
**Copyright:** 2009 Teambox Technologies, S.L.
**Designer:** Saskia Font

## Usage

Icons are automatically selected by the `File_Attachment_Model::get_icon_resource()` method based on file extension. The method returns the relative path to the appropriate icon file.

## Icon Format

- **SVG icons** are used for generic file type categories (scalable, smaller file size)
- **PNG icons** are used for brand-specific applications (48x48px, higher visual quality for recognizable brands)

## Adding New Icons

To add support for a new file type:

1. Add the appropriate icon file to this directory
2. Update the `$icon_map` array in `File_Attachment_Model::get_icon_resource()`
3. Map the file extension to the icon filename
4. Update this README with the icon source and license information
