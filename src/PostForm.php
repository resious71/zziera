<?php
/**
 * 찌라시 등록 입력 검증 (PRD 3.1, WYSIWYG 본문)
 */
final class PostForm
{
    public static function title(array $post): string
    {
        return Input::string($post, 'title', '제목', Config::int('TITLE_MAX', 100));
    }

    /**
     * 본문 HTML 정화 + 이미지 소유 검증. 문제가 있으면 ValidationException(여러 줄 메시지).
     *
     * @return array HtmlSanitizer::sanitize() 결과
     */
    public static function body(array $post): array
    {
        $raw = $post['body_html'] ?? '';
        if (!is_string($raw)) {
            throw new ValidationException('본문 값이 올바르지 않습니다.');
        }
        // 1차: DB에 실제로 있는 이미지만 남긴다(없는 id 는 rejected 로 보고)
        $result = HtmlSanitizer::sanitize($raw, HtmlSanitizer::existingImagesFilter());
        $errors = $result['errors'];

        if ($result['rejected_image_ids'] !== []) {
            $errors[] = '본문의 이미지 일부를 찾을 수 없습니다. 해당 이미지를 지우고 다시 올려 주세요.';
        }
        // 2차: 이 세션이 올렸고 아직 다른 글에 연결되지 않은 임시 이미지만 허용
        if (PendingImages::invalid($result['image_ids']) !== []) {
            $errors[] = '다른 사람이 올렸거나 이미 다른 글에 쓰인 이미지가 본문에 있습니다. 해당 이미지를 지우고 직접 올려 주세요.';
        }
        if ($errors !== []) {
            throw new ValidationException(implode("\n", array_values(array_unique($errors))));
        }
        return $result;
    }
}
