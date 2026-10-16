<?php
/**
 * 본문 HTML 출력용 렌더러
 *
 * 저장된 본문은 이미 정화되어 있지만, 출력 직전에 한 번 더 정화한다(PRD 6장: 저장 시·출력 직전 두 번).
 * 그 뒤 출력용 장식을 붙인다.
 *  - 만료되었거나 파일이 없는 이미지 → <span class="zz-img-expired">이미지가 만료되었습니다</span>
 *  - 표 → <div class="zz-table-wrap"> 로 감싸 모바일에서 가로 스크롤
 * 이 장식(class 속성)은 출력에만 쓰고 DB에는 저장하지 않는다.
 */
final class ContentRenderer
{
    public const EXPIRED_TEXT = '이미지가 만료되었습니다';

    /**
     * 등록된 글의 본문 출력. 이 글에 연결된 이미지(zzira_id = $postId)만 보여 준다.
     */
    public static function render(string $html, int $postId): string
    {
        $filter = static function (array $ids) use ($postId): array {
            return self::existingIds('zzira_id = ?', [$postId], $ids);
        };
        return self::decorate(HtmlSanitizer::sanitize($html, $filter)['html']);
    }

    /**
     * 등록 전 미리보기 출력. 이 세션이 올린 임시 이미지만 보여 준다.
     * @param list<int> $pendingIds
     */
    public static function renderPreview(string $sanitizedHtml, array $pendingIds): string
    {
        $pendingSet = array_fill_keys($pendingIds, true);
        $filter = static function (array $ids) use ($pendingSet): array {
            $mine = array_values(array_filter($ids, static fn (int $id) => isset($pendingSet[$id])));
            return self::existingIds('zzira_id IS NULL', [], $mine);
        };
        return self::decorate(HtmlSanitizer::sanitize($sanitizedHtml, $filter)['html']);
    }

    /** 조건에 맞는 zzira_images id 만 남긴다 */
    private static function existingIds(string $where, array $params, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column(
            Db::all("SELECT id FROM zzira_images WHERE {$where} AND id IN ({$marks})", array_merge($params, array_values($ids))),
            'id'
        ));
    }

    /** 정화된 HTML 에 출력용 장식을 붙인다 */
    private static function decorate(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        // 이미지 가용성: 한 번에 조회
        $imgs = iterator_to_array($body->getElementsByTagName('img'));
        $ids = [];
        foreach ($imgs as $img) {
            $id = HtmlSanitizer::imageIdFromSrc($img->getAttribute('src'));
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $available = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT id, file_path, file_deleted_at FROM zzira_images WHERE id IN ({$marks})", array_values($ids)) as $r) {
                $available[(int) $r['id']] = $r['file_deleted_at'] === null && Upload::absolutePath($r['file_path']) !== null;
            }
        }
        foreach ($imgs as $img) {
            $id = HtmlSanitizer::imageIdFromSrc($img->getAttribute('src'));
            if ($id !== null && ($available[$id] ?? false)) {
                $img->setAttribute('loading', 'lazy');
                continue;
            }
            // <p> 안의 이미지 자리이므로 div 대신 span(inline-block) — div 는 브라우저가 단락을 쪼갠다
            $box = $doc->createElement('span');
            $box->setAttribute('class', 'zz-img-expired');
            $box->appendChild($doc->createTextNode(self::EXPIRED_TEXT));
            $img->parentNode->replaceChild($box, $img);
        }

        foreach (iterator_to_array($body->getElementsByTagName('table')) as $table) {
            $wrap = $doc->createElement('div');
            $wrap->setAttribute('class', 'zz-table-wrap');
            $table->parentNode->replaceChild($wrap, $table);
            $wrap->appendChild($table);
        }

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }
}
