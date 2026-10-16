<?php
/**
 * 감정 이모티콘 목록 — 댓글 입력란의 이모티콘 고르기와 댓글 공감(CommentReactions)이 함께 쓴다.
 *
 *  - 공감은 이 목록에 있는 것만 저장한다(임의 문자열 방지). 댓글 본문에는 어떤 이모티콘이든 글자로 들어간다.
 *  - 화면(assets/comments.js)은 comment_list 응답의 emoji_sets 로 받아 그리므로 목록은 여기 한 곳에만 둔다.
 */
final class Emoji
{
    /** @return list<array{key:string, label:string, items:list<string>}> */
    public static function sets(): array
    {
        return [
            ['key' => 'agree', 'label' => '동의·좋아요', 'items' => ['👍', '👌', '🙆', '🙋', '✅', '💯', '👏', '🙌', '🤝', '💪', '⭕', '🫡', '☝️', '🆗']],
            ['key' => 'reject', 'label' => '반대·거부', 'items' => ['👎', '🙅', '❌', '✋', '🚫', '⛔', '🙄', '😒', '🤨', '🧐', '😮‍💨', '🤦', '🙈']],
            ['key' => 'joy', 'label' => '웃음·기쁨', 'items' => ['😀', '😃', '😄', '😁', '😆', '😂', '🤣', '😊', '😍', '🥰', '😎', '🤩', '🥳', '😋', '😇']],
            ['key' => 'angry', 'label' => '화남·짜증', 'items' => ['😠', '😡', '🤬', '😤', '💢', '👿', '😾', '🗯️', '👊']],
            ['key' => 'sad', 'label' => '슬픔·걱정', 'items' => ['😢', '😭', '😞', '😔', '😟', '😥', '🥺', '😩', '😫', '💔', '🥲']],
            ['key' => 'surprise', 'label' => '놀람·당황', 'items' => ['😮', '😲', '😯', '😱', '😳', '🤯', '😨', '😰', '😵', '🫢', '‼️', '⁉️']],
            ['key' => 'think', 'label' => '생각·의심', 'items' => ['🤔', '🤫', '🤐', '😐', '😑', '😶', '🫤', '😏', '🙃', '🤷', '🫠']],
            ['key' => 'cheer', 'label' => '응원·기타', 'items' => ['🔥', '❤️', '💖', '🎉', '🙏', '🍿', '👀', '💡', '⭐', '🚀', '😴', '🤑']],
        ];
    }

    /** 공감에 쓸 수 있는 이모티콘인지 */
    public static function isAllowed(string $emoji): bool
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (self::sets() as $set) {
                foreach ($set['items'] as $e) {
                    $all[$e] = true;
                }
            }
        }
        return isset($all[$emoji]);
    }
}
