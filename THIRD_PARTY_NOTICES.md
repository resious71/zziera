# Third-party notices / 외부 구성 요소

ZZieRa itself is released under the MIT License (see `LICENSE`).
It bundles the following third-party components, each under its own license.

| Component | Path | Version | License | Source |
| --- | --- | --- | --- | --- |
| PHP-Science-TextRank | `lib/PhpScience/TextRank/` | master @ `3695858` | MIT (`lib/LICENSE-TextRank.txt`) | https://github.com/DavidBelicza/PHP-Science-TextRank |
| PHPMailer | `lib/PHPMailer/` | 7.1.1 | LGPL-2.1 (`lib/PHPMailer/LICENSE`) | https://github.com/PHPMailer/PHPMailer |
| Fluent Emoji "Thought balloon" (3D) | `assets/thought-balloon.png` and the favicons generated from it (`assets/favicon.ico`, `assets/favicon-32.png`, `assets/apple-touch-icon.png`) | — | MIT (below) | https://github.com/microsoft/fluentui-emoji |

The vendored library sources are unmodified; see `lib/SOURCES.md` for exact commits.
PHPMailer is used as an unmodified, separately replaceable library (drop a newer release into `lib/PHPMailer/`), in line with the LGPL-2.1.

## Fluent Emoji — MIT License

```
MIT License

Copyright (c) Microsoft Corporation.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE
```
