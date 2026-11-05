# Contributing

Thanks for your interest! Issues and pull requests are welcome.

1. Fork the repository and create a feature branch.
2. `composer install`, then run the app (see [README](README.md)).
3. Follow the existing style: `declare(strict_types=1);`, PSR-4 under `src/`, PSR-12 formatting.
4. Keep UI strings in German as keys and add the English translation in `public/assets/i18n.js`.
5. Never commit real log files, the `data/` contents or personal data (IP addresses).
6. Describe what and why in your pull request and test with a sample log of the affected format.

New log formats: add a class implementing `LocalWeblogAnalyzer\Format\LineFormat` in `src/Format/`
and register it in `Detector`.

By contributing you agree that your contributions are licensed under the MIT License.
