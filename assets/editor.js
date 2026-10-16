/*
 * ZZieRa 본문 에디터 (contenteditable 기반 자체 구현, 외부 라이브러리 없음)
 *
 * - 서식 명령은 모두 EditorCommands(document.execCommand 래퍼) 한 곳을 거친다. 나중에 교체하기 쉽게 하기 위함.
 * - 표 편집(행·열 추가/삭제), 이미지 업로드 자리표시자는 DOM 을 직접 다룬다.
 * - 붙여넣기·끌어놓기 HTML 은 허용 태그만 남기고 정리한다(편의용). 최종 정화는 서버(HtmlSanitizer)가 한다.
 *
 * 사용: var editor = new ZZEditor({ root, area, csrf, limits, onChange });
 *       editor.getHTML(), editor.stats(), editor.isUploading(), editor.clear()
 */
(function () {
    'use strict';

    // ── execCommand 래퍼: 이 객체 밖에서는 execCommand 를 부르지 않는다 ─────────
    var EditorCommands = {
        exec: function (command, value) {
            try { return document.execCommand(command, false, value === undefined ? null : value); }
            catch (e) { return false; }
        },
        state: function (command) {
            try { return document.queryCommandState(command); } catch (e) { return false; }
        },
        init: function () {
            this.exec('defaultParagraphSeparator', 'p');
            this.exec('styleWithCSS', false);
        }
    };

    // ── 공통 도우미 ───────────────────────────────────────────
    var BLOCK_TAGS = ['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'BLOCKQUOTE', 'DIV', 'TD', 'TH', 'TR', 'UL', 'OL', 'TABLE', 'PRE'];
    var IMAGE_SRC_RE = /^(?:\.\/)?api\/file\.php\?type=image&id=([1-9]\d{0,9})$/;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    function codePoints(s) { return Array.from(s).length; }

    function closest(node, selector, stopAt) {
        while (node && node !== stopAt) {
            if (node.nodeType === 1 && node.matches(selector)) return node;
            node = node.parentNode;
        }
        return null;
    }

    function isHttpUrl(url) {
        // 공백·제어 문자를 지운 뒤 스킴 확인(서버 규칙과 같음)
        var probe = url.replace(/[\u0000- \u007f ​-‍⁠﻿]+/g, '');
        return /^https?:\/\/[^\/\s?#]+/i.test(probe);
    }

    /**
     * 글자 속 http(s) 주소를 링크로 바꾼 HTML(나머지는 이스케이프). quota.left 가 남아 있는 만큼만 링크로 만들고
     * 주소가 너무 길면(maxLen) 글자로 둔다. 끝에 붙은 문장부호·짝 없는 닫는 괄호는 주소에서 뺀다.
     */
    var URL_IN_TEXT_RE = /https?:\/\/[^\s<>"'`\u3000]+/gi;
    function trimUrl(u) {
        for (;;) {
            var last = u.charAt(u.length - 1);
            if (/[.,;:!?'"」』。、…]/.test(last)) { u = u.slice(0, -1); continue; }
            var pairs = { ')': '(', ']': '[', '}': '{' };
            if (pairs[last] && u.split(pairs[last]).length <= u.split(last).length - 1) { u = u.slice(0, -1); continue; }
            return u;
        }
    }
    function hasUrl(text) { URL_IN_TEXT_RE.lastIndex = 0; return URL_IN_TEXT_RE.test(text); }
    function linkifyText(text, quota, maxLen) {
        var out = '', pos = 0, m;
        URL_IN_TEXT_RE.lastIndex = 0;
        while ((m = URL_IN_TEXT_RE.exec(text)) !== null) {
            var url = trimUrl(m[0]);
            if (!isHttpUrl(url) || url.length > maxLen || quota.left <= 0) { if (quota.left <= 0 && isHttpUrl(url)) quota.skipped++; continue; }
            out += escapeHtml(text.slice(pos, m.index)) + '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>';
            pos = m.index + url.length;
            URL_IN_TEXT_RE.lastIndex = pos;
            quota.left--;
        }
        return out + escapeHtml(text.slice(pos));
    }

    /** 이미지 주소에서 id 추출. 같은 출처의 절대 주소도 상대 주소로 바꿔 판정한다 */
    function imageIdFromSrc(src) {
        if (!src) return null;
        try {
            var u = new URL(src, location.href);
            if (u.origin !== location.origin) return null;
            var base = new URL('.', location.href).pathname; // 앱 폴더 경로
            if (u.pathname.indexOf(base) !== 0) return null;
            var rel = u.pathname.slice(base.length) + u.search;
            var m = IMAGE_SRC_RE.exec(rel);
            return m ? Number(m[1]) : null;
        } catch (e) { return null; }
    }

    /** 정화된 HTML → 일반 텍스트(서버 HtmlText::toPlain 과 같은 규칙: 블록 경계 줄바꿈, 표·이미지 제외) */
    function toPlain(root, includeTables) {
        var buf = '';
        (function walk(node) {
            for (var c = node.firstChild; c; c = c.nextSibling) {
                if (c.nodeType === 3) { buf += c.data.replace(/[\r\n]/g, ' '); continue; }
                if (c.nodeType !== 1) continue;
                var tag = c.tagName;
                if (tag === 'IMG' || tag === 'SCRIPT' || tag === 'STYLE') continue;
                if (c.classList && c.classList.contains('zz-upload-ph')) continue;
                if (tag === 'TABLE' && !includeTables) { buf += '\n'; continue; }
                if (tag === 'BR') { buf += '\n'; continue; }
                if (tag === 'TD' || tag === 'TH') { buf += ' '; walk(c); buf += ' '; continue; }
                var block = BLOCK_TAGS.indexOf(tag) >= 0 || tag === 'HR' || tag === 'THEAD' || tag === 'TBODY';
                if (block) buf += '\n';
                walk(c);
                if (block) buf += '\n';
            }
        })(root);
        return buf.replace(/ /g, ' ').split('\n')
            .map(function (l) { return l.replace(/[ \t\f\v]+/g, ' ').trim(); })
            .filter(function (l) { return l !== ''; })
            .join('\n');
    }

    // ── 붙여넣기 HTML 정리(허용 목록) ────────────────────────────
    var DROP_TAGS = ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA',
        'SVG', 'MATH', 'LINK', 'META', 'BASE', 'TEMPLATE', 'NOSCRIPT', 'TITLE', 'HEAD', 'AUDIO', 'VIDEO', 'CANVAS', 'OPTION'];
    var KEEP_BLOCK = { P: 'p', H1: 'h2', H2: 'h2', H3: 'h3', H4: 'h3', H5: 'h3', H6: 'h3', UL: 'ul', OL: 'ol', LI: 'li',
        BLOCKQUOTE: 'blockquote', TABLE: 'table', THEAD: 'thead', TBODY: 'tbody', TR: 'tr', TD: 'td', TH: 'th', PRE: 'p' };
    var KEEP_INLINE = { B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'u' };
    var WRAPPERS = ['DIV', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'MAIN', 'ASIDE', 'NAV', 'FIGURE', 'FIGCAPTION', 'CENTER', 'ADDRESS', 'DL', 'DT', 'DD'];

    /**
     * @param {string} html 붙여넣은 HTML
     * @param {function(number):boolean} canUseImage 이 편집에서 쓸 수 있는 이미지 id 인지
     * @param {{left:number, skipped:number}} quota 글자 속 주소를 링크로 바꿀 수 있는 남은 개수(본문 링크 한도)
     * @param {number} maxLen 링크 주소 최대 길이
     * @return {{html:string, droppedImages:number}}
     */
    function cleanPastedHtml(html, canUseImage, quota, maxLen) {
        var src = new DOMParser().parseFromString(html, 'text/html'); // 비활성 문서: 스크립트 실행·이미지 로드 없음
        var dropped = 0;
        function conv(node, inLink) {
            var out = '';
            for (var c = node.firstChild; c; c = c.nextSibling) {
                if (c.nodeType === 3) { out += inLink ? escapeHtml(c.data) : linkifyText(c.data, quota, maxLen); continue; }
                if (c.nodeType !== 1) continue;
                var tag = c.tagName.toUpperCase();
                if (DROP_TAGS.indexOf(tag) >= 0) continue;
                if (tag === 'BR') { out += '<br>'; continue; }
                if (tag === 'HR') { out += '<hr>'; continue; }
                if (tag === 'IMG') {
                    var id = imageIdFromSrc(c.getAttribute('src'));
                    if (id !== null && canUseImage(id)) out += '<img src="api/file.php?type=image&amp;id=' + id + '" alt="">';
                    else dropped++;
                    continue;
                }
                if (tag === 'A') {
                    var href = (c.getAttribute('href') || '').trim();
                    if (isHttpUrl(href)) out += '<a href="' + escapeHtml(href) + '">' + conv(c, true) + '</a>';
                    else out += conv(c, inLink); // 쓸 수 없는 주소(상대 주소 등)는 글자만, 글자 속 주소는 링크로
                    continue;
                }
                if (KEEP_INLINE[tag]) {
                    var t = KEEP_INLINE[tag];
                    out += '<' + t + '>' + conv(c, inLink) + '</' + t + '>';
                    continue;
                }
                if (KEEP_BLOCK[tag]) {
                    var b = KEEP_BLOCK[tag];
                    var attrs = '';
                    if (b === 'td' || b === 'th') {
                        ['colspan', 'rowspan'].forEach(function (a) {
                            var v = parseInt(c.getAttribute(a), 10);
                            if (v > 1) attrs += ' ' + a + '="' + Math.min(v, 10) + '"';
                        });
                    }
                    out += '<' + b + attrs + '>' + conv(c, inLink) + '</' + b + '>';
                    continue;
                }
                if (WRAPPERS.indexOf(tag) >= 0) { out += '<p>' + conv(c, inLink) + '</p>'; continue; }
                out += conv(c, inLink); // span, font 등: 껍데기만 벗김
            }
            return out;
        }
        return { html: conv(src.body, false), droppedImages: dropped };
    }

    // ── 에디터 ───────────────────────────────────────────────
    function ZZEditor(opts) {
        this.root = opts.root;
        this.area = opts.area;
        this.toolbar = this.root.querySelector('.zz-toolbar');
        this.imageInput = opts.imageInput;
        this.uploadEl = opts.uploadStatus;
        this.countEl = opts.countEl;
        this.noticeEl = opts.noticeEl;
        this.linkDialog = opts.linkDialog;
        this.tableDialog = opts.tableDialog;
        this.csrf = opts.csrf;
        this.limits = opts.limits;
        this.onChange = opts.onChange || function () {};
        this.savedRange = null;
        this.uploadQueue = [];
        this.uploading = 0;
        this.knownImages = {}; // 이 화면에서 올린 이미지 id(붙여넣기 허용 판정용)
        this.internalDrag = false;
        this.editingLink = null;
        this.init();
    }

    ZZEditor.prototype.init = function () {
        var self = this;
        this.area.innerHTML = '<p><br></p>';
        EditorCommands.init();

        // 선택 영역 기억(도구 모음·대화 상자를 쓰는 동안 잃지 않도록)
        document.addEventListener('selectionchange', function () {
            var sel = window.getSelection();
            if (sel.rangeCount && self.area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
                self.savedRange = sel.getRangeAt(0).cloneRange();
                self.updateToolbar();
                return;
            }
            // 포커스는 편집 영역에 있는데 선택만 바깥(아래 상태 표시줄 등)으로 빠져나간 경우:
            // 마지막으로 기억한 위치로 되돌린다(IME 입력 확정 직후 커서가 밖으로 튀는 현상 방지)
            if (document.activeElement === self.area && self.savedRange && self.area.contains(self.savedRange.startContainer)) {
                sel.removeAllRanges();
                sel.addRange(self.savedRange);
            }
        });

        // 도구 모음: 마우스로 누를 때 편집 영역의 포커스를 빼앗지 않는다
        this.toolbar.addEventListener('mousedown', function (e) {
            if (e.target.closest('button')) e.preventDefault();
        });
        this.toolbar.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-cmd]');
            if (btn && !btn.disabled) self.command(btn.getAttribute('data-cmd'));
        });
        this.initToolbarKeyboard();

        // 글자 입력(IME 확정 포함) 직후 커서가 편집 영역 밖으로 튀는 경우가 있어,
        // 입력 직전 위치를 기억했다가 입력 뒤 "넣은 글자 바로 뒤"로 커서를 되돌린다.
        this.area.addEventListener('beforeinput', function (e) {
            var sel = window.getSelection();
            self.pendingInsert = null;
            if (e.data && /^insert(Text|CompositionText|ReplacementText)$/.test(e.inputType) && sel.rangeCount && sel.isCollapsed
                && self.area.contains(sel.anchorNode)) {
                self.pendingInsert = { node: sel.anchorNode, offset: sel.anchorOffset, data: e.data };
            }
        });
        this.area.addEventListener('input', function () {
            self.fixEscapedCaret();
            self.saveSelection();
            self.changed();
        });
        this.area.addEventListener('keyup', function () { self.saveSelection(); self.updateToolbar(); });
        this.area.addEventListener('mouseup', function () { self.saveSelection(); self.updateToolbar(); });
        this.area.addEventListener('keydown', function (e) { self.onKeyDown(e); });
        this.area.addEventListener('paste', function (e) { self.onPaste(e); });
        this.area.addEventListener('dragstart', function () { self.internalDrag = true; });
        this.area.addEventListener('dragend', function () { self.internalDrag = false; });
        this.area.addEventListener('dragover', function (e) {
            if (!self.internalDrag) { e.preventDefault(); self.area.classList.add('is-dragover'); }
        });
        this.area.addEventListener('dragleave', function () { self.area.classList.remove('is-dragover'); });
        this.area.addEventListener('drop', function (e) { self.onDrop(e); });

        this.imageInput.addEventListener('change', function () {
            self.queueImages(Array.prototype.slice.call(self.imageInput.files));
            self.imageInput.value = '';
        });

        this.initLinkDialog();
        this.initTableDialog();
        this.changed();
    };

    // ── 선택 영역 ─────────────────────────────────────────
    /** input 직후: 선택이 편집 영역 밖이면 방금 넣은 글자 바로 뒤로 커서를 옮긴다 */
    ZZEditor.prototype.fixEscapedCaret = function () {
        var p = this.pendingInsert;
        this.pendingInsert = null;
        var sel = window.getSelection();
        if (!p || (sel.rangeCount && this.area.contains(sel.anchorNode)) || !this.area.contains(p.node)) return;
        // 브라우저는 앞뒤 공백을 &nbsp; 로 바꿔 넣으므로 비교할 때는 같은 글자로 본다
        var norm = function (s) { return s.replace(/ /g, ' '); };
        var data = norm(p.data);
        var target = null;
        if (p.node.nodeType === 3) {
            // 글자 노드 안에서 입력: 같은 노드의 (오프셋 + 글자 수)
            if (norm(p.node.data.substr(p.offset, data.length)) === data) target = [p.node, p.offset + data.length];
        } else {
            // 요소 사이(예: 이미지 뒤)에서 입력: 그 자리에 새 글자 노드가 생기거나 앞 글자 노드에 이어 붙는다
            var after = p.node.childNodes[p.offset];
            var before = p.node.childNodes[p.offset - 1];
            if (after && after.nodeType === 3 && norm(after.data).indexOf(data) === 0) target = [after, data.length];
            else if (before && before.nodeType === 3 && norm(before.data).slice(-data.length) === data) target = [before, before.data.length];
        }
        if (!target) return;
        var r = document.createRange();
        r.setStart(target[0], target[1]);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        this.savedRange = r.cloneRange();
    };

    ZZEditor.prototype.saveSelection = function () {
        var sel = window.getSelection();
        if (sel.rangeCount && this.area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            this.savedRange = sel.getRangeAt(0).cloneRange();
        }
    };

    /**
     * 명령 직전에 선택 영역을 되살린다.
     * 편집 영역에 포커스와 선택이 그대로 있으면 그것을 쓴다(selectionchange 는 비동기라 저장본이 늦을 수 있음).
     * 포커스가 도구 모음·대화 상자로 갔던 경우에만 저장해 둔 범위를 되살린다.
     */
    ZZEditor.prototype.restoreSelection = function () {
        var sel = window.getSelection();
        if (document.activeElement === this.area && sel.rangeCount && this.area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            this.savedRange = sel.getRangeAt(0).cloneRange();
            return;
        }
        this.area.focus();
        if (this.savedRange && this.area.contains(this.savedRange.commonAncestorContainer)) {
            sel.removeAllRanges();
            sel.addRange(this.savedRange);
        } else {
            this.placeCaretAtEnd();
        }
    };

    ZZEditor.prototype.placeCaretAtEnd = function () {
        var range = document.createRange();
        range.selectNodeContents(this.area);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        this.savedRange = range.cloneRange();
    };

    ZZEditor.prototype.placeCaretIn = function (el, atEnd) {
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(!atEnd);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        this.savedRange = range.cloneRange();
    };

    ZZEditor.prototype.currentNode = function () {
        var sel = window.getSelection();
        if (!sel.rangeCount) return null;
        var node = sel.getRangeAt(0).startContainer;
        return this.area.contains(node) ? node : null;
    };

    ZZEditor.prototype.currentCell = function () {
        var node = this.currentNode() || (this.savedRange && this.savedRange.startContainer);
        return node ? closest(node, 'td, th', this.area) : null;
    };

    ZZEditor.prototype.currentBlockTag = function () {
        var node = this.currentNode();
        var block = node ? closest(node, 'p, h2, h3, li, blockquote, td, th', this.area) : null;
        return block ? block.tagName.toLowerCase() : '';
    };

    // ── 명령 ──────────────────────────────────────────────
    ZZEditor.prototype.command = function (cmd) {
        var self = this;
        if (['link', 'table', 'image'].indexOf(cmd) < 0) this.restoreSelection();
        var inCell = !!this.currentCell();
        switch (cmd) {
            case 'bold': case 'italic': case 'underline':
                EditorCommands.exec(cmd); break;
            case 'h2': case 'h3':
                if (inCell) return this.notice('표 안에서는 제목을 쓸 수 없습니다.');
                EditorCommands.exec('formatBlock', this.currentBlockTag() === cmd ? '<p>' : '<' + cmd + '>'); break;
            case 'ul': case 'ol':
                if (inCell) return this.notice('표 안에서는 목록을 쓸 수 없습니다.');
                EditorCommands.exec(cmd === 'ul' ? 'insertUnorderedList' : 'insertOrderedList'); break;
            case 'blockquote':
                if (inCell) return this.notice('표 안에서는 인용을 쓸 수 없습니다.');
                if (closest(this.currentNode(), 'blockquote', this.area)) EditorCommands.exec('outdent');
                else EditorCommands.exec('formatBlock', '<blockquote>');
                break;
            case 'hr':
                if (inCell) return this.notice('표 안에는 구분선을 넣을 수 없습니다.');
                EditorCommands.exec('insertHorizontalRule'); break;
            case 'link': this.openLinkDialog(); return;
            case 'unlink':
                var a = closest(this.currentNode(), 'a', this.area);
                if (a) { var r = document.createRange(); r.selectNodeContents(a); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
                EditorCommands.exec('unlink'); break;
            case 'image':
                if (this.imageCount() >= this.limits.inlineImageMax) {
                    return this.notice('본문 이미지는 최대 ' + this.limits.inlineImageMax + '장까지 넣을 수 있습니다.');
                }
                this.imageInput.click(); return;
            case 'table':
                if (inCell) return this.notice('표 안에 표를 넣을 수 없습니다.');
                this.openTableDialog(); return;
            case 'row-add': case 'row-del': case 'col-add': case 'col-del': case 'table-del':
                this.tableCommand(cmd); break;
            case 'clear':
                EditorCommands.exec('removeFormat');
                if (['h2', 'h3'].indexOf(this.currentBlockTag()) >= 0) EditorCommands.exec('formatBlock', '<p>');
                break;
            case 'undo': case 'redo':
                EditorCommands.exec(cmd); break;
        }
        setTimeout(function () { self.changed(); }, 0);
    };

    ZZEditor.prototype.updateToolbar = function () {
        var self = this;
        var block = this.currentBlockTag();
        var inQuote = !!closest(this.currentNode(), 'blockquote', this.area);
        var states = {
            bold: EditorCommands.state('bold'), italic: EditorCommands.state('italic'), underline: EditorCommands.state('underline'),
            ul: EditorCommands.state('insertUnorderedList'), ol: EditorCommands.state('insertOrderedList'),
            h2: block === 'h2', h3: block === 'h3', blockquote: inQuote,
            link: !!closest(this.currentNode(), 'a', this.area)
        };
        Object.keys(states).forEach(function (cmd) {
            var btn = self.toolbar.querySelector('[data-cmd="' + cmd + '"]');
            if (btn) btn.setAttribute('aria-pressed', states[cmd] ? 'true' : 'false');
        });
        var inCell = !!this.currentCell();
        this.toolbar.querySelectorAll('.zz-table-tools button').forEach(function (b) { b.disabled = !inCell; });
        ['h2', 'h3', 'ul', 'ol', 'blockquote', 'hr', 'table'].forEach(function (cmd) {
            self.toolbar.querySelector('[data-cmd="' + cmd + '"]').disabled = inCell;
        });
        this.area.querySelectorAll('.zz-current-cell').forEach(function (c) { c.classList.remove('zz-current-cell'); });
        if (inCell) this.currentCell().classList.add('zz-current-cell');
        this.refreshRoving();
    };

    // 도구 모음 키보드 조작: 화살표·Home·End 로 이동(roving tabindex), Alt+F10 으로 진입
    ZZEditor.prototype.initToolbarKeyboard = function () {
        var self = this;
        this.toolbar.addEventListener('keydown', function (e) {
            var buttons = self.enabledButtons();
            var idx = buttons.indexOf(document.activeElement);
            if (idx < 0) return;
            var next = null;
            if (e.key === 'ArrowRight') next = buttons[(idx + 1) % buttons.length];
            else if (e.key === 'ArrowLeft') next = buttons[(idx - 1 + buttons.length) % buttons.length];
            else if (e.key === 'Home') next = buttons[0];
            else if (e.key === 'End') next = buttons[buttons.length - 1];
            else if (e.key === 'Escape') { e.preventDefault(); self.restoreSelection(); return; }
            if (next) { e.preventDefault(); self.setRoving(next); next.focus(); }
        });
        this.toolbar.addEventListener('focusin', function (e) {
            if (e.target.matches('button')) self.setRoving(e.target);
        });
        this.refreshRoving();
    };
    ZZEditor.prototype.enabledButtons = function () {
        return Array.prototype.filter.call(this.toolbar.querySelectorAll('button'), function (b) { return !b.disabled; });
    };
    ZZEditor.prototype.setRoving = function (target) {
        this.toolbar.querySelectorAll('button').forEach(function (b) { b.tabIndex = b === target ? 0 : -1; });
    };
    ZZEditor.prototype.refreshRoving = function () {
        var current = this.toolbar.querySelector('button[tabindex="0"]');
        if (!current || current.disabled) this.setRoving(this.enabledButtons()[0]);
    };

    ZZEditor.prototype.onKeyDown = function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); this.openLinkDialog(); return; }
        if (e.altKey && e.key === 'F10') { e.preventDefault(); var f = this.toolbar.querySelector('button[tabindex="0"]'); if (f) f.focus(); return; }
        if (e.key === 'Tab') {
            var cell = this.currentCell();
            if (!cell) return; // 표 밖: 기본 동작(다음 입력 칸으로 이동)
            e.preventDefault();
            this.moveCell(cell, e.shiftKey ? -1 : 1);
        }
    };

    // ── 표 ────────────────────────────────────────────────
    ZZEditor.prototype.openTableDialog = function () {
        var d = this.tableDialog;
        d.querySelector('#table-error').textContent = '';
        d.showModal();
        d.querySelector('#table-rows').focus();
    };

    ZZEditor.prototype.initTableDialog = function () {
        var self = this;
        var d = this.tableDialog;
        d.querySelector('[data-close]').addEventListener('click', function () { d.close(); self.restoreSelection(); });
        d.querySelector('form').addEventListener('submit', function (e) {
            e.preventDefault();
            var rows = parseInt(d.querySelector('#table-rows').value, 10);
            var cols = parseInt(d.querySelector('#table-cols').value, 10);
            var L = self.limits;
            if (!(rows >= 1 && rows <= L.tableRowsMax && cols >= 1 && cols <= L.tableColsMax)) {
                d.querySelector('#table-error').textContent = '행은 1~' + L.tableRowsMax + ', 열은 1~' + L.tableColsMax + ' 사이로 입력하세요.';
                return;
            }
            d.close();
            self.insertTable(rows, cols, d.querySelector('#table-header').checked);
        });
    };

    ZZEditor.prototype.insertTable = function (rows, cols, header) {
        var cell = function (tag) { return '<' + tag + '><br></' + tag + '>'; };
        var row = function (tag) { var h = '<tr>'; for (var c = 0; c < cols; c++) h += cell(tag); return h + '</tr>'; };
        var html = '<table>';
        if (header) html += '<thead>' + row('th') + '</thead>';
        html += '<tbody>';
        for (var r = header ? 1 : 0; r < rows; r++) html += row('td');
        html += '</tbody></table><p><br></p>';
        this.restoreSelection();
        EditorCommands.exec('insertHTML', html);
        // 방금 넣은 표의 첫 칸으로 커서 이동
        var tables = this.area.querySelectorAll('table');
        var sel = window.getSelection();
        var near = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
        var target = null;
        for (var i = 0; i < tables.length; i++) {
            if (near && (tables[i].compareDocumentPosition(near) & Node.DOCUMENT_POSITION_FOLLOWING)) target = tables[i];
        }
        target = target || tables[tables.length - 1];
        if (target) this.placeCaretIn(target.querySelector('th, td'), false);
        this.changed();
    };

    ZZEditor.prototype.tableInfo = function (cell) {
        var table = closest(cell, 'table', this.area);
        var rows = Array.prototype.slice.call(table.rows);
        var cols = rows.reduce(function (m, r) { return Math.max(m, r.cells.length); }, 0);
        return { table: table, rows: rows, cols: cols, row: cell.parentNode, rowIndex: rows.indexOf(cell.parentNode), cellIndex: cell.cellIndex };
    };

    ZZEditor.prototype.newCell = function (tag) {
        var c = document.createElement(tag);
        c.appendChild(document.createElement('br'));
        return c;
    };

    ZZEditor.prototype.tableCommand = function (cmd) {
        var cell = this.currentCell();
        if (!cell) return;
        var t = this.tableInfo(cell);
        var L = this.limits;
        var self = this;
        if (cmd === 'row-add') {
            if (t.rows.length >= L.tableRowsMax) return this.notice('표는 최대 ' + L.tableRowsMax + '행까지 만들 수 있습니다.');
            var tr = document.createElement('tr');
            for (var c = 0; c < t.cols; c++) tr.appendChild(this.newCell('td'));
            if (t.row.parentNode.tagName === 'THEAD') {
                var tbody = t.table.tBodies[0] || t.table.appendChild(document.createElement('tbody'));
                tbody.insertBefore(tr, tbody.firstChild);
            } else {
                t.row.parentNode.insertBefore(tr, t.row.nextSibling);
            }
            this.placeCaretIn(tr.cells[Math.min(t.cellIndex, tr.cells.length - 1)], false);
        } else if (cmd === 'row-del') {
            if (t.rows.length <= 1) return this.removeTable(t.table);
            var target = t.rows[t.rowIndex + 1] || t.rows[t.rowIndex - 1];
            var section = t.row.parentNode;
            section.removeChild(t.row);
            if (!section.rows.length) section.parentNode.removeChild(section);
            this.placeCaretIn(target.cells[Math.min(t.cellIndex, target.cells.length - 1)], false);
        } else if (cmd === 'col-add') {
            if (t.cols >= L.tableColsMax) return this.notice('표는 최대 ' + L.tableColsMax + '열까지 만들 수 있습니다.');
            t.rows.forEach(function (r) {
                var ref = r.cells[t.cellIndex];
                var nc = self.newCell(r.parentNode.tagName === 'THEAD' ? 'th' : (ref ? ref.tagName.toLowerCase() : 'td'));
                r.insertBefore(nc, ref ? ref.nextSibling : null);
            });
            this.placeCaretIn(t.row.cells[t.cellIndex + 1] || cell, false);
        } else if (cmd === 'col-del') {
            if (t.cols <= 1) return this.removeTable(t.table);
            t.rows.forEach(function (r) { if (r.cells[t.cellIndex]) r.removeChild(r.cells[t.cellIndex]); });
            var stay = t.row.cells[Math.min(t.cellIndex, t.row.cells.length - 1)];
            if (stay) this.placeCaretIn(stay, false);
        } else if (cmd === 'table-del') {
            this.removeTable(t.table);
        }
        this.changed();
    };

    ZZEditor.prototype.removeTable = function (table) {
        var next = table.nextElementSibling;
        if (!next || next.tagName !== 'P') {
            next = document.createElement('p');
            next.appendChild(document.createElement('br'));
            table.parentNode.insertBefore(next, table.nextSibling);
        }
        table.parentNode.removeChild(table);
        this.placeCaretIn(next, false);
        this.changed();
    };

    ZZEditor.prototype.moveCell = function (cell, dir) {
        var t = this.tableInfo(cell);
        var cells = [];
        t.rows.forEach(function (r) { Array.prototype.push.apply(cells, r.cells); });
        var idx = cells.indexOf(cell) + dir;
        if (idx >= 0 && idx < cells.length) {
            this.placeCaretIn(cells[idx], true);
        } else if (dir > 0) {
            if (t.rows.length < this.limits.tableRowsMax) {
                this.tableCommand('row-add');
                var last = t.table.rows[t.table.rows.length - 1];
                this.placeCaretIn(last.cells[0], false);
            } else {
                this.notice('표는 최대 ' + this.limits.tableRowsMax + '행까지 만들 수 있습니다.');
            }
        }
        this.updateToolbar();
    };

    // ── 링크 ──────────────────────────────────────────────
    ZZEditor.prototype.openLinkDialog = function () {
        var d = this.linkDialog;
        var node = this.currentNode() || (this.savedRange && this.savedRange.startContainer);
        this.editingLink = node ? closest(node, 'a', this.area) : null;
        var sel = window.getSelection();
        var selectedText = sel.rangeCount && this.area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.toString() : '';
        d.querySelector('#link-url').value = this.editingLink ? this.editingLink.getAttribute('href') : '';
        d.querySelector('#link-text').value = this.editingLink ? this.editingLink.textContent : selectedText;
        d.querySelector('#link-error').textContent = '';
        d.showModal();
        d.querySelector('#link-url').focus();
    };

    ZZEditor.prototype.initLinkDialog = function () {
        var self = this;
        var d = this.linkDialog;
        d.querySelector('[data-close]').addEventListener('click', function () { d.close(); self.restoreSelection(); });
        d.querySelector('form').addEventListener('submit', function (e) {
            e.preventDefault();
            var url = d.querySelector('#link-url').value.trim();
            var text = d.querySelector('#link-text').value.trim();
            var err = d.querySelector('#link-error');
            if (!isHttpUrl(url)) { err.textContent = 'http:// 또는 https:// 로 시작하는 주소만 넣을 수 있습니다.'; return; }
            if (url.length > self.limits.linkUrlMax) { err.textContent = '주소는 ' + self.limits.linkUrlMax + '자 이하로 입력하세요.'; return; }
            if (!self.editingLink && self.linkCount() >= self.limits.bodyLinkMax) {
                err.textContent = '본문 링크는 최대 ' + self.limits.bodyLinkMax + '개까지 넣을 수 있습니다.'; return;
            }
            d.close();
            if (self.editingLink && self.area.contains(self.editingLink)) {
                self.editingLink.setAttribute('href', url);
                if (text) self.editingLink.textContent = text;
                self.placeCaretIn(self.editingLink, true);
            } else {
                self.restoreSelection();
                EditorCommands.exec('insertHTML', '<a href="' + escapeHtml(url) + '">' + escapeHtml(text || url) + '</a>');
            }
            self.changed();
        });
    };

    // ── 이미지 ────────────────────────────────────────────
    ZZEditor.prototype.imageCount = function () {
        return this.area.querySelectorAll('img').length + this.area.querySelectorAll('.zz-upload-ph').length;
    };
    ZZEditor.prototype.linkCount = function () { return this.area.querySelectorAll('a[href]').length; };

    ZZEditor.prototype.checkImageFile = function (file) {
        var L = this.limits;
        var parts = file.name.toLowerCase().split('.');
        if (parts.length < 2) return '"' + file.name + '": 확장자가 없는 파일은 올릴 수 없습니다.';
        for (var i = 1; i < parts.length; i++) {
            if (L.blockedExts.indexOf(parts[i].trim()) >= 0) return '"' + file.name + '": 실행 파일이나 스크립트는 올릴 수 없습니다.';
        }
        if (L.imageExts.indexOf(parts[parts.length - 1]) < 0) return '"' + file.name + '": 이미지는 ' + L.imageExts.join(', ') + ' 형식만 넣을 수 있습니다.';
        if (file.size > L.imageMaxBytes) return '"' + file.name + '": 이미지는 장당 ' + Math.round(L.imageMaxBytes / 1048576) + 'MB까지 올릴 수 있습니다.';
        if (file.size === 0) return '"' + file.name + '": 빈 파일입니다.';
        return null;
    };

    /** 이미지 파일들을 현재 커서 위치(또는 range)에 자리표시자로 넣고 차례로 업로드한다 */
    ZZEditor.prototype.queueImages = function (files, range) {
        var self = this;
        var errors = [];
        files.forEach(function (file) {
            if (!/^image\//.test(file.type) && self.limits.imageExts.indexOf((file.name.split('.').pop() || '').toLowerCase()) < 0) {
                errors.push('"' + file.name + '": 이미지 파일만 본문에 넣을 수 있습니다. 문서는 아래 첨부파일에 추가하세요.');
                return;
            }
            if (self.imageCount() >= self.limits.inlineImageMax) {
                errors.push('본문 이미지는 최대 ' + self.limits.inlineImageMax + '장까지 넣을 수 있습니다.');
                return;
            }
            var err = self.checkImageFile(file);
            if (err) { errors.push(err); return; }
            var ph = self.insertPlaceholder(range);
            range = null; // 두 번째 파일부터는 앞 자리표시자 뒤에
            self.uploadQueue.push({ file: file, ph: ph });
        });
        this.notice(errors.filter(function (v, i, a) { return a.indexOf(v) === i; }).join('\n'));
        this.changed();
        this.pumpUploads();
    };

    ZZEditor.prototype.insertPlaceholder = function (range) {
        var ph = document.createElement('span');
        ph.className = 'zz-upload-ph';
        ph.contentEditable = 'false';
        ph.textContent = '[이미지 올리는 중…]';
        if (range) {
            var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        } else {
            this.restoreSelection();
        }
        // 표 칸 안에는 이미지를 넣을 수 없으므로 표 바로 뒤에 넣는다
        var cell = this.currentCell();
        var r = window.getSelection().getRangeAt(0);
        if (cell) {
            var table = closest(cell, 'table', this.area);
            var p = document.createElement('p');
            table.parentNode.insertBefore(p, table.nextSibling);
            p.appendChild(ph);
            this.notice('표 안에는 이미지를 넣을 수 없어 표 아래에 넣었습니다.');
        } else {
            r.deleteContents();
            r.insertNode(ph);
        }
        // 커서를 자리표시자 뒤로
        var after = document.createRange();
        after.setStartAfter(ph);
        after.collapse(true);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(after);
        this.savedRange = after.cloneRange();
        return ph;
    };

    ZZEditor.prototype.pumpUploads = function () {
        var self = this;
        if (this.uploading || !this.uploadQueue.length) return;
        var job = this.uploadQueue.shift();
        this.uploading = 1;
        var fd = new FormData();
        fd.append('csrf_token', this.csrf);
        fd.append('image', job.file, job.file.name);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/upload_image.php');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) self.setUploadStatus('이미지 올리는 중… ' + Math.round(e.loaded / e.total * 100) + '%' + (self.uploadQueue.length ? ' (남은 ' + self.uploadQueue.length + '장)' : ''));
        };
        xhr.onload = xhr.onerror = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { /* JSON 아님 */ }
            if (data && data.ok) {
                var img = document.createElement('img');
                img.setAttribute('src', data.url);
                img.setAttribute('alt', '');
                self.knownImages[data.id] = true;
                if (job.ph.parentNode) {
                    // 커서가 자리표시자 바로 뒤에 있었다면 교체 뒤에도 이미지 뒤에 두어야 한다
                    // (그대로 두면 경계가 이미지 앞으로 붙어 이어 치는 글이 이미지 앞에 들어간다)
                    var sel = window.getSelection();
                    var parent = job.ph.parentNode;
                    var idx = Array.prototype.indexOf.call(parent.childNodes, job.ph);
                    var caretAfter = sel.rangeCount && sel.isCollapsed && sel.anchorNode === parent && sel.anchorOffset === idx + 1;
                    var savedAfter = self.savedRange && self.savedRange.collapsed && self.savedRange.startContainer === parent && self.savedRange.startOffset === idx + 1;
                    parent.replaceChild(img, job.ph);
                    if (caretAfter || savedAfter) {
                        var r = document.createRange();
                        r.setStartAfter(img);
                        r.collapse(true);
                        if (caretAfter) { sel.removeAllRanges(); sel.addRange(r); }
                        self.savedRange = r.cloneRange();
                    }
                }
                self.setUploadStatus('');
            } else {
                if (job.ph.parentNode) job.ph.parentNode.removeChild(job.ph);
                if (xhr.status === 401 && data && data.error === 'login_required') {
                    data.error = data.message || '로그인이 필요합니다.';
                    if (window.ZZ && window.ZZ.loginRequired) window.ZZ.loginRequired();
                }
                var msg = (data && data.error) || (xhr.status === 413 ? '이미지가 너무 큽니다.' : '이미지를 올리지 못했습니다. 네트워크 상태를 확인하세요.');
                self.setUploadStatus('"' + job.file.name + '" 업로드 실패: ' + msg, true);
            }
            self.uploading = 0;
            self.changed();
            self.pumpUploads();
        };
        this.setUploadStatus('이미지 올리는 중…');
        xhr.send(fd);
    };

    ZZEditor.prototype.setUploadStatus = function (text, isError) {
        this.uploadEl.textContent = text;
        this.uploadEl.classList.toggle('error', !!isError);
    };

    ZZEditor.prototype.isUploading = function () { return this.uploading > 0 || this.uploadQueue.length > 0; };

    // ── 붙여넣기·끌어놓기 ──────────────────────────────────
    ZZEditor.prototype.canUseImage = function (id) {
        if (this.knownImages[id]) return true;
        var imgs = this.area.querySelectorAll('img');
        for (var i = 0; i < imgs.length; i++) if (imageIdFromSrc(imgs[i].getAttribute('src')) === id) return true;
        return false;
    };

    ZZEditor.prototype.insertCleanHtml = function (html, text) {
        var self = this;
        if (this.currentCell()) {
            // 표 칸에는 인라인만: 글자로 붙인다(글자 속 주소는 링크로)
            this.insertPlainText(text || toPlain(new DOMParser().parseFromString(html, 'text/html').body, true));
            return;
        }
        var quota = this.linkQuota();
        var cleaned = cleanPastedHtml(html, function (id) { return self.canUseImage(id); }, quota, this.limits.linkUrlMax);
        this.noticeLinkQuota(quota);
        if (cleaned.droppedImages) {
            this.notice('외부 이미지나 그림 데이터는 붙여넣을 수 없어 ' + cleaned.droppedImages + '개를 뺐습니다. 이미지는 파일로 올리거나 이미지 파일 자체를 붙여넣으세요.');
        }
        if (cleaned.html.replace(/<[^>]*>/g, '').trim() === '' && cleaned.html.indexOf('<img') < 0 && cleaned.html.indexOf('<table') < 0) {
            if (text) this.insertPlainText(text);
            return;
        }
        EditorCommands.exec('insertHTML', cleaned.html);
    };

    /** 본문 링크 한도(BODY_LINK_MAX)까지 남은 개수 */
    ZZEditor.prototype.linkQuota = function () {
        return { left: Math.max(0, this.limits.bodyLinkMax - this.linkCount()), skipped: 0 };
    };
    ZZEditor.prototype.noticeLinkQuota = function (quota) {
        if (quota.skipped) this.notice('본문 링크는 최대 ' + this.limits.bodyLinkMax + '개까지라 주소 ' + quota.skipped + '개는 링크 없이 글자로 붙였습니다.');
    };

    /**
     * 글자 붙여넣기. 주소(http/https)가 들어 있으면 링크로 바꿔 넣는다(주소창·메신저·메모장에서 복사한 경우).
     *  - 글자를 선택한 채 주소 하나만 붙여넣으면 선택한 글자를 그 주소의 링크로 만든다.
     *  - 주소가 없으면 예전처럼 그대로 글자로 넣는다.
     */
    ZZEditor.prototype.insertPlainText = function (text) {
        if (!text) return;
        if (!hasUrl(text)) { EditorCommands.exec('insertText', text); return; }
        var quota = this.linkQuota();
        var max = this.limits.linkUrlMax;
        var one = text.trim();
        var sel = window.getSelection();
        var selected = sel.rangeCount && !sel.isCollapsed && this.area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.toString() : '';
        if (selected.trim() && !/\s/.test(one) && trimUrl(one) === one && isHttpUrl(one) && one.length <= max && !/[\r\n]/.test(selected)) {
            if (quota.left > 0) {
                EditorCommands.exec('insertHTML', '<a href="' + escapeHtml(one) + '">' + escapeHtml(selected) + '</a>');
                return;
            }
            quota.skipped++;
        }
        var lines = text.replace(/\r\n?/g, '\n').split('\n');
        var html;
        if (lines.length === 1 || this.currentCell()) {
            html = linkifyText(lines.join(' '), quota, max); // 한 줄·표 칸: 인라인으로
        } else {
            html = lines.map(function (l) { var h = linkifyText(l, quota, max); return '<p>' + (h.trim() ? h : '<br>') + '</p>'; }).join('');
        }
        this.noticeLinkQuota(quota);
        EditorCommands.exec('insertHTML', html);
    };

    ZZEditor.prototype.onPaste = function (e) {
        var cd = e.clipboardData;
        if (!cd) return;
        var files = Array.prototype.filter.call(cd.files || [], function (f) { return /^image\//.test(f.type); });
        e.preventDefault();
        if (files.length) { this.queueImages(files); return; }
        var html = cd.getData('text/html');
        var text = cd.getData('text/plain');
        if (html) this.insertCleanHtml(html, text);
        else if (text) this.insertPlainText(text);
        this.changed();
    };

    ZZEditor.prototype.rangeFromPoint = function (x, y) {
        if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
        if (document.caretPositionFromPoint) {
            var pos = document.caretPositionFromPoint(x, y);
            if (!pos) return null;
            var r = document.createRange(); r.setStart(pos.offsetNode, pos.offset); r.collapse(true); return r;
        }
        return null;
    };

    ZZEditor.prototype.onDrop = function (e) {
        this.area.classList.remove('is-dragover');
        if (this.internalDrag) return; // 에디터 안에서 글자를 옮기는 경우는 기본 동작
        e.preventDefault();
        var dt = e.dataTransfer;
        var range = this.rangeFromPoint(e.clientX, e.clientY);
        if (range && !this.area.contains(range.startContainer)) range = null;
        var files = Array.prototype.slice.call(dt.files || []);
        if (files.length) { this.area.focus(); this.queueImages(files, range); return; }
        if (range) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(range); this.savedRange = range.cloneRange(); }
        else this.restoreSelection();
        var html = dt.getData('text/html');
        var text = dt.getData('text/plain');
        if (html) this.insertCleanHtml(html, text);
        else if (text) this.insertPlainText(text);
        this.changed();
    };

    // ── 상태·출력 ─────────────────────────────────────────
    ZZEditor.prototype.notice = function (text) {
        this.noticeEl.textContent = text || '';
    };

    ZZEditor.prototype.stats = function () {
        var text = toPlain(this.area, false);
        var cellOver = false;
        var max = this.limits.tableCellMax;
        this.area.querySelectorAll('td, th').forEach(function (c) { if (codePoints(c.textContent.trim()) > max) cellOver = true; });
        return {
            textLength: codePoints(text),
            hasText: toPlain(this.area, true) !== '',
            imageCount: this.area.querySelectorAll('img').length,
            linkCount: this.linkCount(),
            tableCount: this.area.querySelectorAll('table').length,
            cellOver: cellOver,
            uploading: this.isUploading()
        };
    };

    /**
     * 브라우저 편집 명령이 넣는 style·id·class 속성을 지운다.
     * 서버는 이 속성들을 모두 버리므로, 에디터에서도 지워야 보이는 모양이 상세 화면과 같다(WYSIWYG).
     * 속성만 지우고 노드는 옮기지 않으므로 커서 위치가 바뀌지 않는다.
     */
    ZZEditor.prototype.scrub = function () {
        this.area.querySelectorAll('[style], [id], [class], [color], [face], [size], [align], [dir], [lang]').forEach(function (n) {
            ['style', 'id', 'color', 'face', 'size', 'align', 'dir', 'lang'].forEach(function (a) { n.removeAttribute(a); });
            if (n.hasAttribute('class')) {
                var keep = Array.prototype.filter.call(n.classList, function (c) { return c.indexOf('zz-') === 0; });
                if (keep.length) n.className = keep.join(' '); else n.removeAttribute('class');
            }
        });
    };

    /**
     * 블록 구조 정리(서버 HtmlSanitizer 의 규칙과 맞춘다):
     *  1) 블록(목록·표·단락 등)을 품은 p/h2/h3 는 껍데기를 벗긴다(브라우저가 만드는 <p><ul>… 형태).
     *  2) 최상위·인용 안에 맨몸으로 놓인 글자·인라인 요소는 <p> 로 감싼다.
     * 노드를 옮기면 선택 영역이 흐트러지므로, 같은 노드·오프셋으로 다시 걸어 준다(노드 객체는 그대로 유지됨).
     */
    ZZEditor.prototype.normalizeBlocks = function () {
        var area = this.area;
        var BLOCK_SEL = 'ul, ol, table, p, h1, h2, h3, h4, h5, h6, blockquote, hr, div, pre';
        var needUnwrap = Array.prototype.filter.call(area.querySelectorAll('p, h2, h3'), function (el) {
            return Array.prototype.some.call(el.children, function (c) { return c.matches(BLOCK_SEL); });
        });
        var isInline = function (n) {
            if (n.nodeType === 3) return true;
            return n.nodeType === 1 && !n.matches(BLOCK_SEL + ', li, tr, td, th, thead, tbody');
        };
        var containers = [area].concat(Array.prototype.slice.call(area.querySelectorAll('blockquote')));
        var needWrap = containers.some(function (c) {
            return Array.prototype.some.call(c.childNodes, function (n) {
                return isInline(n) && !(n.nodeType === 3 && n.data.trim() === '');
            });
        });
        if (!needUnwrap.length && !needWrap) return;

        var sel = window.getSelection();
        var keep = sel.rangeCount && area.contains(sel.anchorNode)
            ? { an: sel.anchorNode, ao: sel.anchorOffset, fn: sel.focusNode, fo: sel.focusOffset } : null;

        // 1) 안쪽부터 껍데기 벗기기
        needUnwrap.reverse().forEach(function (el) {
            if (!el.parentNode) return;
            while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
            el.parentNode.removeChild(el);
        });
        // 2) 맨몸 인라인 묶음을 <p> 로 감싸기
        containers.forEach(function (c) {
            if (!c.parentNode && c !== area) return;
            var run = [];
            var flush = function () {
                var meaningful = run.some(function (n) { return !(n.nodeType === 3 && n.data.trim() === ''); });
                if (meaningful) {
                    var p = document.createElement('p');
                    c.insertBefore(p, run[0]);
                    run.forEach(function (n) { p.appendChild(n); });
                }
                run = [];
            };
            Array.prototype.slice.call(c.childNodes).forEach(function (n) {
                if (isInline(n)) run.push(n); else flush();
            });
            flush();
        });

        if (keep && area.contains(keep.an) && area.contains(keep.fn)) {
            try { sel.setBaseAndExtent(keep.an, keep.ao, keep.fn, keep.fo); } catch (e) { /* 범위가 바뀐 경우 무시 */ }
            this.saveSelection();
        }
    };

    ZZEditor.prototype.changed = function () {
        this.scrub();
        this.normalizeBlocks();
        var s = this.stats();
        var L = this.limits;
        var parts = [
            '글 ' + s.textLength.toLocaleString() + ' / ' + L.bodyTextMax.toLocaleString() + '자',
            '이미지 ' + (s.imageCount + this.area.querySelectorAll('.zz-upload-ph').length) + ' / ' + L.inlineImageMax,
            '링크 ' + s.linkCount + ' / ' + L.bodyLinkMax
        ];
        this.countEl.textContent = parts.join(' · ');
        this.countEl.classList.toggle('over', s.textLength > L.bodyTextMax || s.imageCount > L.inlineImageMax || s.linkCount > L.bodyLinkMax || s.cellOver);
        this.area.classList.toggle('is-empty', !s.hasText && !s.imageCount && !s.tableCount && !this.area.querySelector('hr, .zz-upload-ph'));
        this.updateToolbar();
        this.onChange(s);
    };

    /** 서버로 보낼 본문 HTML(편집용 표시는 뺀다). 비어 있으면 빈 문자열 */
    ZZEditor.prototype.getHTML = function () {
        var clone = this.area.cloneNode(true);
        clone.querySelectorAll('.zz-upload-ph').forEach(function (n) { n.parentNode.removeChild(n); });
        clone.querySelectorAll('.zz-current-cell').forEach(function (n) { n.classList.remove('zz-current-cell'); if (!n.className) n.removeAttribute('class'); });
        var s = this.stats();
        if (!s.hasText && !s.imageCount && !s.tableCount && !clone.querySelector('hr')) return '';
        return clone.innerHTML;
    };

    ZZEditor.prototype.clear = function () {
        this.area.innerHTML = '<p><br></p>';
        this.uploadQueue = [];
        this.setUploadStatus('');
        this.notice('');
        this.savedRange = null;
        this.changed();
    };

    ZZEditor.toPlain = toPlain;
    window.ZZEditor = ZZEditor;
})();
