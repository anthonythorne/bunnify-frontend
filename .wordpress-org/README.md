# WordPress.org listing assets

Files in this directory are deployed to the WordPress.org plugin SVN `assets/`
folder by `.github/workflows/deploy.yml` (they are **not** shipped inside the
plugin zip). Add the standard assets here before the first release:

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Plugin icon (also supply `icon-256x256.png` for retina) |
| `icon-256x256.png` | 256×256 | Retina plugin icon |
| `banner-772x250.png` | 772×250 | Header banner |
| `banner-1544x500.png` | 1544×500 | Retina header banner |
| `screenshot-1.png` | any | Matches `== Screenshots ==` entry 1 in `readme.txt` |

See the [WordPress.org plugin assets guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
