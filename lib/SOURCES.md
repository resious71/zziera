# lib/ 외부 소스 출처

| 경로 | 출처 | 버전 | 라이선스 | 반입일 |
| --- | --- | --- | --- | --- |
| `PhpScience/TextRank/` | https://github.com/DavidBelicza/PHP-Science-TextRank (`src/` 폴더 그대로) | master @ `3695858236d8ce3a604e9b4b5b7d1bca18a518b9` | MIT (`LICENSE-TextRank.txt`) | 2026-10-06 |

- `lib/` 소스는 직접 수정하지 않는다. 호환성 문제는 `src/` 어댑터(`TextRankAdapter`)에서 우회한다.
- 불가피하게 수정하면 `lib/PATCHES.md` 에 파일·줄·이유를 기록한다(현재 수정 없음).

| 경로 | 출처 | 버전 | 라이선스 | 반입일 |
| --- | --- | --- | --- | --- |
| `PHPMailer/` | https://github.com/PHPMailer/PHPMailer (`src/PHPMailer.php`, `src/SMTP.php`, `src/Exception.php`, `language/phpmailer.lang-ko.php`) | v7.1.1 @ `36d2461b1b0ca3d133873d5568d466b4cb7729e7` | LGPL-2.1 (`PHPMailer/LICENSE`) | 2026-10-06 |

- PHPMailer 는 SMTP 발송에만 쓴다(PHP `mail()` 방식은 쓰지 않는다). 없으면 메일 화면에만 안내가 나오고 앱의 나머지는 정상 동작한다.
