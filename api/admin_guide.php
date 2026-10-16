<?php
/** 관리자 가이드(PRD 12장) 본문 — 관리자 인증 필요. 관리 탭 "관리자 가이드"가 불러 그대로 보여 준다(서버가 만든 고정 HTML). */
require __DIR__ . '/../src/bootstrap.php';
Json::installErrorHandler();
Auth::requireAdmin();
Auth::ok(['html' => Guide::adminHtml()]);
