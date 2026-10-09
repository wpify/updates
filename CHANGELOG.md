# Changelog

All notable changes to this package are documented here.

## [1.2.0]

### Added
- Polish, German, Hungarian and Romanian translations (alongside Czech and Slovak).
- A locale without its own translation uses another locale of the same language, e.g. de_AT, de_CH and de_DE_formal use the German one.

## [1.1.0]

### Added
- When WPify does not offer an automatic update (e.g. the license is not valid for the site), the plugin list says why, in the admin's language.
- A refused update download shows the reason from WPify instead of a bare "Bad Request".
- `wpify_updates_metadata` action (`$slug`, `$metadata`) after every update check, so other packages can read their data from the response without a request of their own.
- Czech and Slovak translations (text domain `wpify-updates`).

### Changed
- On multisite, update checks always send the main site's URL: plugins are installed and updated for the whole network.
