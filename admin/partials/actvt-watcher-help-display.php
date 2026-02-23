<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
// Guard
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}

/**
 * Super-lightweight Markdown → HTML converter.
 * Handles: headings, bold, italic, inline code, fenced code blocks,
 * tables, blockquotes, unordered/ordered lists, horizontal rules, links, paragraphs.
 *
 * No external library required.
 *
 * @param string $md Raw Markdown text.
 * @return string HTML string (NOT yet escaped — only call with trusted content).
 */
function actvt_parse_markdown( $md ) {
    $html  = '';
    $lines = preg_split( '/\r\n|\n/', $md );
    $i     = 0;
    $n     = count( $lines );

    while ( $i < $n ) {
        $line = $lines[ $i ];

        // ── Fenced code block (``` ... ```) ──────────────────────────────
        if ( preg_match( '/^```(.*)$/', $line, $m ) ) {
            $lang  = trim( $m[1] );
            $code  = '';
            $i++;
            while ( $i < $n && ! preg_match( '/^```/', $lines[ $i ] ) ) {
                $code .= htmlspecialchars( $lines[ $i ], ENT_QUOTES ) . "\n";
                $i++;
            }
            $html .= '<pre><code' . ( $lang ? ' class="language-' . esc_attr( $lang ) . '"' : '' ) . '>' . $code . '</code></pre>' . "\n";
            $i++;
            continue;
        }

        // ── Horizontal rule ───────────────────────────────────────────────
        if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', trim( $line ) ) ) {
            $html .= '<hr>' . "\n";
            $i++;
            continue;
        }

        // ── ATX Headings (#) ──────────────────────────────────────────────
        if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
            $level = strlen( $m[1] );
            $id    = sanitize_title( $m[2] );
            $html .= "<h{$level} id=\"{$id}\">" . actvt_md_inline( $m[2] ) . "</h{$level}>\n";
            $i++;
            continue;
        }

        // ── Blockquote ────────────────────────────────────────────────────
        if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
            $bq = '';
            while ( $i < $n && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $bm ) ) {
                $bq .= actvt_md_inline( $bm[1] ) . ' ';
                $i++;
            }
            $html .= '<blockquote><p>' . trim( $bq ) . '</p></blockquote>' . "\n";
            continue;
        }

        // ── Unordered list ────────────────────────────────────────────────
        if ( preg_match( '/^[-*+]\s+(.+)$/', $line, $m ) ) {
            $html .= '<ul>' . "\n";
            while ( $i < $n && preg_match( '/^[-*+]\s+(.+)$/', $lines[ $i ], $lm ) ) {
                $html .= '<li>' . actvt_md_inline( $lm[1] ) . '</li>' . "\n";
                $i++;
            }
            $html .= '</ul>' . "\n";
            continue;
        }

        // ── Ordered list ──────────────────────────────────────────────────
        if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $m ) ) {
            $html .= '<ol>' . "\n";
            while ( $i < $n && preg_match( '/^\d+\.\s+(.+)$/', $lines[ $i ], $lm ) ) {
                $html .= '<li>' . actvt_md_inline( $lm[1] ) . '</li>' . "\n";
                $i++;
            }
            $html .= '</ol>' . "\n";
            continue;
        }

        // ── Table ─────────────────────────────────────────────────────────
        if ( strpos( $line, '|' ) !== false && $i + 1 < $n && preg_match( '/^\|?[\s:|-]+\|/', $lines[ $i + 1 ] ) ) {
            $html .= '<table><thead><tr>';
            foreach ( array_slice( explode( '|', trim( $line, '|' ) ), 0 ) as $cell ) {
                $html .= '<th>' . actvt_md_inline( trim( $cell ) ) . '</th>';
            }
            $html .= '</tr></thead><tbody>' . "\n";
            $i += 2; // skip separator row
            while ( $i < $n && strpos( $lines[ $i ], '|' ) !== false ) {
                $html .= '<tr>';
                foreach ( array_slice( explode( '|', trim( $lines[ $i ], '|' ) ), 0 ) as $cell ) {
                    $html .= '<td>' . actvt_md_inline( trim( $cell ) ) . '</td>';
                }
                $html .= '</tr>' . "\n";
                $i++;
            }
            $html .= '</tbody></table>' . "\n";
            continue;
        }

        // ── Blank line or paragraph break ─────────────────────────────────
        if ( trim( $line ) === '' ) {
            $i++;
            continue;
        }

        // ── Paragraph ─────────────────────────────────────────────────────
        $para = '';
        while ( $i < $n && trim( $lines[ $i ] ) !== ''
            && ! preg_match( '/^(#{1,6}\s|```|>|[-*+]\s|\d+\.|---|\|)/', $lines[ $i ] ) ) {
            $para .= $lines[ $i ] . ' ';
            $i++;
        }
        $html .= '<p>' . actvt_md_inline( trim( $para ) ) . '</p>' . "\n";
    }

    return $html;
}

/**
 * Convert inline Markdown (bold, italic, code, links) to HTML.
 *
 * @param string $text Inline markdown text.
 * @return string HTML.
 */
function actvt_md_inline( $text ) {
    // Inline code (process before bold/italic to protect backtick contents)
    $text = preg_replace_callback( '/`([^`]+)`/', function( $m ) {
        return '<code>' . htmlspecialchars( $m[1], ENT_QUOTES ) . '</code>';
    }, $text );

    // Bold + italic ***text***
    $text = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text );
    // Bold **text**
    $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
    // Italic *text*
    $text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
    // Links [text](url)
    $text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text );

    return $text;
}

// ── Read USER_GUIDE.md ───────────────────────────────────────────────────────────
$readme_path = ACTVT_WATCHER_PATH . 'USER_GUIDE.md';
$readme_raw  = file_exists( $readme_path ) ? file_get_contents( $readme_path ) : '# Documentation\n\n_USER_GUIDE.md not found._';
$readme_html = actvt_parse_markdown( $readme_raw );

// Build TOC from h2/h3 headings
preg_match_all( '/<h([23]) id="([^"]+)">(.+?)<\/h[23]>/i', $readme_html, $toc_matches, PREG_SET_ORDER );
?>

<div class="wrap actvt-help-wrap">

<style>
/* ── Layout ─────────────────────────────────── */
.actvt-help-wrap { display:flex; gap:0; align-items:flex-start; max-width:1400px; }
.actvt-help-sidebar {
    width:220px;
    flex-shrink:0;
    position:sticky;
    top:32px;
    background:#fff;
    border:1px solid #c3c4c7;
    border-radius:4px;
    padding:16px 0;
    margin-right:24px;
}
.actvt-help-sidebar h3 {
    margin:0 0 8px;
    padding:0 16px 8px;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:#8c8f94;
    border-bottom:1px solid #f0f0f1;
}
.actvt-help-sidebar ul { margin:0; padding:0; list-style:none; }
.actvt-help-sidebar li { border-left:3px solid transparent; }
.actvt-help-sidebar li a {
    display:block;
    padding:5px 16px;
    font-size:13px;
    color:#1d2327;
    text-decoration:none;
}
.actvt-help-sidebar li a:hover { color:#2271b1; }
.actvt-help-sidebar li.toc-h3 a { padding-left:28px; font-size:12px; color:#646970; }
.actvt-help-sidebar li.active { border-left-color:#2271b1; }
.actvt-help-sidebar li.active a { color:#2271b1; font-weight:600; }

/* ── Content ─────────────────────────────────── */
.actvt-help-content {
    flex:1;
    background:#fff;
    border:1px solid #c3c4c7;
    border-radius:4px;
    padding:32px 40px;
    min-width:0;
}
.actvt-help-content h1 { font-size:22px; margin-top:0; padding-bottom:12px; border-bottom:2px solid #f0f0f1; }
.actvt-help-content h2 { font-size:17px; margin-top:40px; padding-bottom:8px; border-bottom:1px solid #f0f0f1; color:#1d2327; }
.actvt-help-content h3 { font-size:14px; margin-top:24px; color:#2271b1; }
.actvt-help-content p { line-height:1.7; color:#3c434a; }
.actvt-help-content ul, .actvt-help-content ol { padding-left:24px; line-height:1.8; color:#3c434a; }
.actvt-help-content li { margin-bottom:4px; }
.actvt-help-content code {
    background:#f6f7f7;
    border:1px solid #dcdcde;
    border-radius:3px;
    padding:1px 5px;
    font-size:12px;
    font-family:'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
}
.actvt-help-content pre {
    background:#1e1e1e;
    color:#d4d4d4;
    padding:16px 20px;
    border-radius:4px;
    overflow-x:auto;
}
.actvt-help-content pre code {
    background:none;
    border:none;
    padding:0;
    color:inherit;
    font-size:13px;
}
.actvt-help-content table {
    border-collapse:collapse;
    width:100%;
    margin:16px 0;
    font-size:13px;
}
.actvt-help-content th {
    background:#f6f7f7;
    padding:8px 12px;
    text-align:left;
    border:1px solid #dcdcde;
    font-weight:600;
}
.actvt-help-content td {
    padding:8px 12px;
    border:1px solid #dcdcde;
    vertical-align:top;
    line-height:1.5;
}
.actvt-help-content tr:nth-child(even) td { background:#fafafa; }
.actvt-help-content blockquote {
    border-left:4px solid #2271b1;
    background:#f0f6fc;
    margin:16px 0;
    padding:12px 16px;
    border-radius:0 4px 4px 0;
}
.actvt-help-content blockquote p { margin:0; color:#2c3338; }
.actvt-help-content hr { border:none; border-top:1px solid #f0f0f1; margin:32px 0; }
.actvt-help-content a { color:#2271b1; }
</style>

<!-- Sidebar TOC -->
<nav class="actvt-help-sidebar" id="actvt-toc">
    <h3>Contents</h3>
    <ul>
    <?php foreach ( $toc_matches as $t ) :
        $level = $t[1]; $id = $t[2]; $label = strip_tags( $t[3] );
    ?>
        <li class="toc-h<?php echo esc_attr( $level ); ?>" data-target="<?php echo esc_attr( $id ); ?>">
            <a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
        </li>
    <?php endforeach; ?>
    </ul>
</nav>

<!-- Main content -->
<article class="actvt-help-content" id="actvt-help-content">
    <?php echo $readme_html; // already sanitized by parser ?>
</article>

</div><!-- .actvt-help-wrap -->

<script>
(function() {
    // Scrollspy: highlight active TOC item
    var items  = document.querySelectorAll('#actvt-toc li[data-target]');
    var heads  = document.querySelectorAll('.actvt-help-content h2[id], .actvt-help-content h3[id]');
    if ( ! items.length || ! heads.length ) return;

    function onScroll() {
        var scrollY = window.scrollY || window.pageYOffset;
        var active  = heads[0].id;
        heads.forEach(function(h) {
            if ( h.getBoundingClientRect().top + scrollY - 80 <= scrollY ) {
                active = h.id;
            }
        });
        items.forEach(function(li) {
            li.classList.toggle('active', li.dataset.target === active);
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Smooth scroll on click
    items.forEach(function(li) {
        li.querySelector('a').addEventListener('click', function(e) {
            var target = document.getElementById(li.dataset.target);
            if ( target ) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
})();
</script>
