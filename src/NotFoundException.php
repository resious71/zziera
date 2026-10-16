<?php
/**
 * 대상이 없거나 볼 수 없음(삭제된 글 등). API 에서는 404 + 메시지로 바뀐다(Json::installErrorHandler).
 */
final class NotFoundException extends RuntimeException
{
}
