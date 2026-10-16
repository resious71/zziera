<?php
/**
 * 본문 HTML 정화기 (허용 목록 방식) — 보안의 핵심
 *
 * 입력 HTML을 DOMDocument 로 파싱한 뒤, 허용된 태그·속성만 "새 문서"에 다시 만들어 넣는다.
 * 원본 노드를 고쳐 쓰지 않고 새로 만들기 때문에 허용 목록 밖의 것은 구조적으로 남을 수 없다.
 *
 *  - 허용 태그: p br strong em u h2 h3 ul ol li blockquote hr a img table thead tbody tr th td
 *    (b→strong, i→em, h1→h2, h4~h6→h3 로 정규화)
 *  - 허용 속성: a[href], img[src, alt], th/td[colspan, rowspan]. 그 외 속성은 모두 버린다.
 *  - 위험 태그(script, style, iframe, svg 등)는 내용까지 통째로 버리고,
 *    그 밖의 모르는 태그(span, div, font 등)는 껍데기만 벗기고 내용은 살린다.
 *  - 최상위(블록 문맥)에 놓인 글자·인라인 요소는 <p> 로 감싼다.
 *  - 결과를 다시 정화해도 같은 결과가 나온다(멱등).
 */
final class HtmlSanitizer
{
    /** 내용까지 통째로 제거하는 태그 */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'option', 'optgroup', 'textarea', 'svg', 'math', 'link', 'meta', 'base',
        'template', 'noscript', 'noframes', 'noembed', 'title', 'head', 'audio', 'video', 'source',
        'track', 'canvas', 'map', 'area', 'param', 'xml', 'datalist', 'output', 'dialog', 'portal',
    ];

    /** 블록 경계로 취급하되 태그는 버리는 요소(내용은 살림) */
    private const BLOCK_WRAPPERS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'figcaption',
        'center', 'address', 'details', 'summary', 'fieldset', 'legend', 'dl', 'dt', 'dd', 'caption', 'body', 'html',
    ];

    /** 인라인으로 허용하는 서식 태그(정규화 후 이름) */
    private const INLINE_FORMAT = ['strong', 'em', 'u'];

    private const TAG_ALIASES = [
        'b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'pre' => 'p',
    ];

    private const IMAGE_SRC_PATTERN = '#^(?:\./)?api/file\.php\?type=image&id=([1-9][0-9]{0,9})$#';
    private const ALT_MAX = 200;
    private const SPAN_MAX = 10;

    private DOMDocument $out;
    /** @var callable(list<int>):list<int>|null 허용할 이미지 id 를 걸러 주는 함수 */
    private $imageFilter;
    private array $errors = [];
    private array $imageIds = [];
    private array $rejectedImageIds = [];
    private int $linkCount = 0;
    private int $tableCount = 0;
    private bool $tableTooLarge = false;

    /**
     * @param callable(list<int>):list<int>|null $imageFilter
     *        본문에 나온 이미지 id 목록을 받아 "허용할 id 목록"을 돌려준다.
     *        null 이면 형식만 맞으면 모두 허용(테스트·재정화용이 아니라면 쓰지 않는다).
     * @return array{html:string, text:string, image_ids:list<int>, rejected_image_ids:list<int>,
     *               has_image:bool, has_table:bool, has_link:bool, errors:list<string>}
     */
    public static function sanitize(string $html, ?callable $imageFilter = null): array
    {
        $s = new self();
        $s->imageFilter = $imageFilter;
        return $s->run($html);
    }

    /** DB 기준 이미지 필터: zzira_images 에 실제로 있는 id 만 허용 */
    public static function existingImagesFilter(): callable
    {
        return static function (array $ids): array {
            if ($ids === []) {
                return [];
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            return array_map('intval', array_column(
                Db::all("SELECT id FROM zzira_images WHERE id IN ({$marks})", array_values($ids)),
                'id'
            ));
        };
    }

    private function run(string $html): array
    {
        $rawMax = Config::int('BODY_HTML_MAX', 204800) * 5;
        if (strlen($html) > $rawMax) {
            return $this->result('', ['본문이 너무 큽니다. 내용을 줄여 주세요.']);
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            return $this->result('', ['본문의 문자 인코딩이 올바르지 않습니다(UTF-8만 지원).']);
        }

        $src = $this->parse($html);
        // 감싸는 div 를 쓰면 입력의 남는 </div> 가 그 div 를 일찍 닫아 뒷부분이 사라지므로 body 를 기준으로 한다
        $root = $src->getElementsByTagName('body')->item(0);

        $this->out = new DOMDocument('1.0', 'UTF-8');
        $container = $this->out->createElement('div');
        $this->out->appendChild($container);

        if ($root !== null) {
            // 1차: 이미지 id 를 모아 허용 여부를 한 번에 판정
            $allowedImages = $this->resolveImages($root);
            $this->processBlockChildren($root, $container, $allowedImages);
        }
        $this->tidyParagraphs($container);

        $clean = '';
        foreach (iterator_to_array($container->childNodes) as $child) {
            $clean .= $this->out->saveHTML($child);
        }
        return $this->result($clean, $this->errors);
    }

    // ── 파싱 ────────────────────────────────────────────────────

    private function parse(string $html): DOMDocument
    {
        // 널 바이트·제어 문자 제거(탭·줄바꿈 제외)
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html) ?? '';
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // HTML 파서는 외부 엔티티를 읽지 않으며, LIBXML_NONET 으로 네트워크 접근도 막는다.
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    /** 본문의 이미지 id 를 모아 필터를 거친 "허용 id" 집합을 만든다 */
    private function resolveImages(DOMElement $root): array
    {
        $ids = [];
        foreach ($root->getElementsByTagName('img') as $img) {
            $id = self::imageIdFromSrc($img->getAttribute('src'));
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $allowed = $this->imageFilter === null ? array_values($ids) : ($this->imageFilter)(array_values($ids));
        $allowedSet = array_fill_keys(array_map('intval', $allowed), true);
        foreach ($ids as $id) {
            if (!isset($allowedSet[$id])) {
                $this->rejectedImageIds[$id] = $id;
            }
        }
        return $allowedSet;
    }

    /** 'api/file.php?type=image&id=N' 형식이면 N, 아니면 null */
    public static function imageIdFromSrc(string $src): ?int
    {
        $src = trim($src);
        return preg_match(self::IMAGE_SRC_PATTERN, $src, $m) ? (int) $m[1] : null;
    }

    public static function imageSrc(int $id): string
    {
        return 'api/file.php?type=image&id=' . $id;
    }

    // ── 블록 문맥 ───────────────────────────────────────────────

    /**
     * 블록 문맥(최상위, blockquote 안): 인라인 내용은 <p> 로 모아 감싼다.
     */
    private function processBlockChildren(DOMNode $src, DOMElement $dst, array $allowedImages): void
    {
        $pending = null; // 인라인 내용을 모으는 중인 <p>
        $flush = function () use (&$pending, $dst): void {
            if ($pending !== null) {
                $this->trimTrailingBreaks($pending);
                $dst->appendChild($pending);
                $pending = null;
            }
        };

        foreach (iterator_to_array($src->childNodes) as $node) {
            if ($node instanceof DOMText) {
                if ($pending === null && trim($node->data) === '') {
                    continue; // 블록 사이의 공백
                }
                $pending ??= $this->out->createElement('p');
                $this->appendText($pending, $node->data);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue; // 주석, 처리 명령 등
            }
            $tag = $this->tagName($node);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }
            if (in_array($tag, ['p', 'h2', 'h3'], true)) {
                $flush();
                $el = $this->out->createElement($tag);
                $this->processInlineChildren($node, $el, $allowedImages, false);
                $this->trimTrailingBreaks($el);
                $dst->appendChild($el);
                continue;
            }
            if ($tag === 'ul' || $tag === 'ol') {
                $flush();
                $this->processList($node, $dst, $tag, $allowedImages);
                continue;
            }
            if ($tag === 'blockquote') {
                $flush();
                $el = $this->out->createElement('blockquote');
                $this->processBlockChildren($node, $el, $allowedImages);
                if ($el->hasChildNodes()) {
                    $dst->appendChild($el);
                }
                continue;
            }
            if ($tag === 'hr') {
                $flush();
                $dst->appendChild($this->out->createElement('hr'));
                continue;
            }
            if ($tag === 'table') {
                $flush();
                $this->processTable($node, $dst);
                continue;
            }
            if (in_array($tag, ['li', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot'], true) || in_array($tag, self::BLOCK_WRAPPERS, true)) {
                // 블록 경계: 앞뒤 단락을 끊고 내용은 같은 블록 문맥으로 처리
                $flush();
                $this->processBlockChildren($node, $dst, $allowedImages);
                continue;
            }
            // 인라인 요소(a, img, br, strong, 모르는 인라인 태그 등) → <p> 에 모은다
            $pending ??= $this->out->createElement('p');
            $this->processInlineNode($node, $pending, $allowedImages, false);
        }
        $flush();
    }

    private function processList(DOMElement $src, DOMElement $dst, string $tag, array $allowedImages): void
    {
        $list = $this->out->createElement($tag);
        $loose = null; // li 밖에 놓인 내용을 담을 li
        foreach (iterator_to_array($src->childNodes) as $node) {
            if ($node instanceof DOMElement && $this->tagName($node) === 'li') {
                $loose = null;
                $li = $this->out->createElement('li');
                $this->processListItem($node, $li, $allowedImages);
                if ($this->hasContent($li)) {
                    $list->appendChild($li);
                }
                continue;
            }
            if ($node instanceof DOMElement && in_array($this->tagName($node), ['ul', 'ol'], true)) {
                // 브라우저가 만드는 <ul><li>..</li><ul>..</ul></ul> 형태: 앞 li 안으로 넣는다
                $target = $list->lastChild instanceof DOMElement ? $list->lastChild : null;
                if ($target === null) {
                    $target = $this->out->createElement('li');
                    $list->appendChild($target);
                }
                $this->processList($node, $target, $this->tagName($node), $allowedImages);
                continue;
            }
            if ($node instanceof DOMText && trim($node->data) === '') {
                continue;
            }
            if ($loose === null) {
                $loose = $this->out->createElement('li');
                $list->appendChild($loose);
            }
            $this->processInlineNode($node, $loose, $allowedImages, false);
        }
        foreach (iterator_to_array($list->childNodes) as $li) {
            if (!$this->hasContent($li)) {
                $list->removeChild($li);
            }
        }
        if ($list->hasChildNodes()) {
            $dst->appendChild($list);
        }
    }

    /** li 안: 인라인 내용 + 중첩 목록 */
    private function processListItem(DOMElement $src, DOMElement $li, array $allowedImages): void
    {
        foreach (iterator_to_array($src->childNodes) as $node) {
            if ($node instanceof DOMElement && in_array($this->tagName($node), ['ul', 'ol'], true)) {
                $this->processList($node, $li, $this->tagName($node), $allowedImages);
                continue;
            }
            $this->processInlineNode($node, $li, $allowedImages, false);
        }
        $this->trimTrailingBreaks($li);
    }

    // ── 인라인 문맥 ─────────────────────────────────────────────

    private function processInlineChildren(DOMNode $src, DOMElement $dst, array $allowedImages, bool $inCell): void
    {
        foreach (iterator_to_array($src->childNodes) as $node) {
            $this->processInlineNode($node, $dst, $allowedImages, $inCell);
        }
    }

    /**
     * 인라인 문맥(p, h2, h3, li, td 안 등)의 노드 하나를 처리한다.
     * $inCell: 표 셀 안이면 이미지·목록·표를 허용하지 않는다.
     */
    private function processInlineNode(DOMNode $node, DOMElement $dst, array $allowedImages, bool $inCell): void
    {
        if ($node instanceof DOMText) {
            $this->appendText($dst, $node->data);
            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }
        $tag = $this->tagName($node);

        if (in_array($tag, self::DROP_WITH_CONTENT, true) || $tag === 'hr') {
            return;
        }
        if ($tag === 'table') {
            if (!$inCell) {
                // 인라인 문맥(목록 등) 안의 표: 글자만 살린다
                $this->appendBreakIfNeeded($dst);
                $this->processInlineChildren($node, $dst, $allowedImages, true);
            }
            return; // 셀 안의 중첩 표는 통째로 제거
        }
        if ($tag === 'br') {
            $dst->appendChild($this->out->createElement('br'));
            return;
        }
        if ($tag === 'img') {
            if ($inCell) {
                return;
            }
            $id = self::imageIdFromSrc($node->getAttribute('src'));
            if ($id === null || !isset($allowedImages[$id])) {
                return; // 외부 주소, data: URI, 허용되지 않은 id
            }
            $img = $this->out->createElement('img');
            $img->setAttribute('src', self::imageSrc($id));
            $img->setAttribute('alt', mb_substr(Input::clean($node->getAttribute('alt')), 0, self::ALT_MAX));
            $dst->appendChild($img);
            $this->imageIds[] = $id;
            return;
        }
        if ($tag === 'a') {
            $href = self::normalizeUrl($node->getAttribute('href'));
            if ($href === null || $this->isInsideLink($dst)) {
                $this->processInlineChildren($node, $dst, $allowedImages, $inCell); // 링크만 풀고 내용은 살림
                return;
            }
            $a = $this->out->createElement('a');
            $a->setAttribute('href', $href);
            $a->setAttribute('rel', 'noopener noreferrer');
            $a->setAttribute('target', '_blank');
            $this->processInlineChildren($node, $a, $allowedImages, $inCell);
            if ($this->hasContent($a)) {
                $dst->appendChild($a);
                $this->linkCount++;
            }
            return;
        }
        if (in_array($tag, self::INLINE_FORMAT, true)) {
            $el = $this->out->createElement($tag);
            $this->processInlineChildren($node, $el, $allowedImages, $inCell);
            if ($el->hasChildNodes()) {
                $dst->appendChild($el);
            }
            return;
        }
        if (in_array($tag, ['p', 'h2', 'h3', 'li', 'blockquote', 'ul', 'ol', 'tr'], true) || in_array($tag, self::BLOCK_WRAPPERS, true)) {
            // 인라인 문맥 안의 블록: 줄바꿈으로 구분하고 내용만 살린다
            $this->appendBreakIfNeeded($dst);
            $this->processInlineChildren($node, $dst, $allowedImages, $inCell);
            return;
        }
        // span, font, label 등 모르는 태그: 껍데기만 벗긴다
        $this->processInlineChildren($node, $dst, $allowedImages, $inCell);
    }

    // ── 표 ──────────────────────────────────────────────────────

    private function processTable(DOMElement $src, DOMElement $dst): void
    {
        $maxRows = Config::int('TABLE_ROWS_MAX', 20);
        $maxCols = Config::int('TABLE_COLS_MAX', 10);
        $cellMax = Config::int('TABLE_CELL_MAX', 200);
        $tableNo = ++$this->tableCount;

        // 행 수집: table 직속 tr, thead/tbody/tfoot 안의 tr (중첩 표의 tr 은 제외)
        $headRows = [];
        $bodyRows = [];
        foreach (iterator_to_array($src->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = $this->tagName($child);
            if ($tag === 'tr') {
                $bodyRows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach (iterator_to_array($child->childNodes) as $tr) {
                    if ($tr instanceof DOMElement && $this->tagName($tr) === 'tr') {
                        if ($tag === 'thead') {
                            $headRows[] = $tr;
                        } else {
                            $bodyRows[] = $tr;
                        }
                    }
                }
            }
        }

        $label = $this->tableCount > 1 ? "{$tableNo}번째 표" : '표';
        if (count($headRows) + count($bodyRows) > $maxRows) {
            $this->errors[] = "{$label}는 최대 {$maxRows}행까지 만들 수 있습니다.";
            $this->tableTooLarge = true;
            return;
        }

        $table = $this->out->createElement('table');
        foreach (['thead' => $headRows, 'tbody' => $bodyRows] as $section => $rows) {
            if ($rows === []) {
                continue;
            }
            $sectionEl = $this->out->createElement($section);
            foreach ($rows as $r => $tr) {
                $rowEl = $this->out->createElement('tr');
                $cols = 0;
                foreach (iterator_to_array($tr->childNodes) as $cell) {
                    if (!$cell instanceof DOMElement || !in_array($this->tagName($cell), ['td', 'th'], true)) {
                        continue;
                    }
                    $cellEl = $this->out->createElement($this->tagName($cell));
                    foreach (['colspan', 'rowspan'] as $attr) {
                        $v = trim($cell->getAttribute($attr));
                        if ($v !== '' && ctype_digit($v) && (int) $v > 1) {
                            $cellEl->setAttribute($attr, (string) min((int) $v, self::SPAN_MAX));
                        }
                    }
                    $cols += max(1, (int) $cellEl->getAttribute('colspan'));
                    $this->processInlineChildren($cell, $cellEl, [], true);
                    $this->trimTrailingBreaks($cellEl);
                    if (mb_strlen(trim($cellEl->textContent)) > $cellMax) {
                        $this->errors[] = "{$label}의 한 칸에 {$cellMax}자를 넘는 글이 있습니다. 칸마다 {$cellMax}자 이하로 입력하세요.";
                        $this->tableTooLarge = true;
                        return;
                    }
                    $rowEl->appendChild($cellEl);
                }
                if ($cols > $maxCols) {
                    $this->errors[] = "{$label}는 최대 {$maxCols}열까지 만들 수 있습니다.";
                    $this->tableTooLarge = true;
                    return;
                }
                if ($rowEl->hasChildNodes()) {
                    $sectionEl->appendChild($rowEl);
                }
            }
            if ($sectionEl->hasChildNodes()) {
                $table->appendChild($sectionEl);
            }
        }
        if ($table->hasChildNodes()) {
            $dst->appendChild($table);
        }
    }

    // ── URL ─────────────────────────────────────────────────────

    /**
     * 링크 주소 정규화. 엔티티(&#106; 등)는 DOM 파싱 단계에서 이미 풀린 상태다.
     *  1) 스킴 판정용 문자열에서 공백·제어·폭 없는 문자를 모두 지운다(java\tscript:, 대소문자 혼합 우회 차단).
     *  2) 그 결과가 http:// 또는 https:// 로 시작할 때만 허용한다.
     *  3) 저장용 주소는 앞뒤 공백을 자르고, 안쪽 공백과 비 ASCII 문자는 퍼센트 인코딩한다(멱등).
     * 허용되지 않으면 null.
     */
    public static function normalizeUrl(string $href): ?string
    {
        $invisible = '/[\x00-\x20\x7F\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u';
        $probe = preg_replace($invisible, '', $href) ?? '';
        if (!preg_match('#^https?://#i', $probe)) {
            return null;
        }
        $url = trim(preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $href) ?? '');
        // 스킴 앞에 남은 공백 등은 위 probe 로 이미 걸렀으므로, 남은 것은 경로·질의 안의 문자다
        $url = preg_replace_callback('/[^\x21-\x7E]/u', static fn ($m) => rawurlencode($m[0]), $url) ?? '';
        $url = preg_replace_callback('#^https?#i', static fn ($m) => strtolower($m[0]), $url) ?? '';
        if ($url === '' || strlen($url) > Config::int('LINK_URL_MAX', 500) || !Input::isHttpUrl($url)) {
            return null;
        }
        return $url;
    }

    // ── 도우미 ──────────────────────────────────────────────────

    private function tagName(DOMElement $el): string
    {
        $tag = strtolower($el->localName ?? $el->nodeName);
        return self::TAG_ALIASES[$tag] ?? $tag;
    }

    private function appendText(DOMElement $dst, string $text): void
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        if ($text === '') {
            return;
        }
        $last = $dst->lastChild;
        if ($last instanceof DOMText) {
            $last->appendData($text);
        } else {
            $dst->appendChild($this->out->createTextNode($text));
        }
    }

    private function appendBreakIfNeeded(DOMElement $dst): void
    {
        if ($this->hasContent($dst) && !($dst->lastChild instanceof DOMElement && $dst->lastChild->nodeName === 'br')) {
            $dst->appendChild($this->out->createElement('br'));
        }
    }

    private function trimTrailingBreaks(DOMElement $el): void
    {
        // 내용이 있는 요소의 끝 <br> 은 브라우저에서 보이지 않으므로 제거(멱등성)
        while ($el->lastChild instanceof DOMElement && $el->lastChild->nodeName === 'br' && $el->childNodes->length > 1) {
            $el->removeChild($el->lastChild);
        }
    }

    private function isInsideLink(DOMNode $node): bool
    {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            if ($n instanceof DOMElement && $n->nodeName === 'a') {
                return true;
            }
        }
        return false;
    }

    /** 보이는 내용(글자·이미지)이 있는지 */
    private function hasContent(DOMNode $node): bool
    {
        if (trim(str_replace("\u{00A0}", ' ', $node->textContent)) !== '') {
            return true;
        }
        return $node instanceof DOMElement && $node->getElementsByTagName('img')->length > 0;
    }

    /**
     * 빈 단락 정리: 내용 없는 <p> 는 <p><br></p> 로 통일하고, 연속된 빈 단락은 하나로,
     * 맨 앞·맨 뒤의 빈 단락은 제거한다.
     */
    private function tidyParagraphs(DOMElement $container): void
    {
        $prevEmpty = true; // 맨 앞의 빈 단락 제거
        foreach (iterator_to_array($container->childNodes) as $node) {
            $isP = $node instanceof DOMElement && in_array($node->nodeName, ['p', 'h2', 'h3'], true);
            if ($isP && !$this->hasContent($node)) {
                if ($prevEmpty || $node->nodeName !== 'p') {
                    $container->removeChild($node);
                    continue;
                }
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
                $node->appendChild($this->out->createElement('br'));
                $prevEmpty = true;
                continue;
            }
            $prevEmpty = false;
        }
        while ($container->lastChild instanceof DOMElement && $container->lastChild->nodeName === 'p' && !$this->hasContent($container->lastChild)) {
            $container->removeChild($container->lastChild);
        }
    }

    private function result(string $html, array $errors): array
    {
        $text = $html === '' ? '' : HtmlText::toPlain($html);
        $tableText = $html === '' ? '' : HtmlText::toPlain($html, true);
        $hasTable = str_contains($html, '<table');

        if ($html !== '') {
            $textMax = Config::int('BODY_TEXT_MAX', 10000);
            if (mb_strlen($text) > $textMax) {
                $errors[] = '본문 글자 수가 ' . number_format(mb_strlen($text)) . '자입니다. ' . number_format($textMax) . '자 이하로 줄여 주세요.';
            }
            $htmlMax = Config::int('BODY_HTML_MAX', 204800);
            if (strlen($html) > $htmlMax) {
                $errors[] = '본문이 너무 큽니다(서식 포함 ' . round(strlen($html) / 1024) . 'KB). ' . round($htmlMax / 1024) . 'KB 이하로 줄여 주세요.';
            }
            $imgMax = Config::int('INLINE_IMAGE_MAX', 5);
            if (count($this->imageIds) > $imgMax) {
                $errors[] = "본문 이미지는 최대 {$imgMax}장까지 넣을 수 있습니다(현재 " . count($this->imageIds) . '장).';
            }
            $linkMax = Config::int('BODY_LINK_MAX', 10);
            if ($this->linkCount > $linkMax) {
                $errors[] = "본문 링크는 최대 {$linkMax}개까지 넣을 수 있습니다(현재 {$this->linkCount}개).";
            }
        }
        if ($errors === [] && trim($tableText) === '' && $this->imageIds === [] && !$hasTable && !$this->tableTooLarge) {
            $errors[] = '본문을 입력하세요.';
        }

        return [
            'html'               => $html,
            'text'               => $text,
            'image_ids'          => array_values(array_unique($this->imageIds)),
            'rejected_image_ids' => array_values($this->rejectedImageIds),
            'has_image'          => $this->imageIds !== [],
            'has_table'          => $hasTable,
            'has_link'           => $this->linkCount > 0,
            'errors'             => array_values(array_unique($errors)),
        ];
    }
}
