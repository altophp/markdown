# Differential Fuzz Verdicts

Run: `php bin/fuzz-differential.php --cases=10000 --seed=20260706`

Summary: 10000 generated inputs, 398 output mismatches, 45 minimized structural
representatives, 0 Alto internal warnings.

Policy for this file: these are explained differences between Alto's
spec-green renderer and `league/commonmark` on fuzz-generated edges. They are
accepted for T3.10 because CommonMark conformance remains 652/652. Do not
change parser behavior for one of these cases without either a CommonMark
example, a second oracle, or a focused product decision.

| Case | Family | Verdict |
| --- | --- | --- |
| case-000.md | html-type1-closing-tag | Accepted exception: standalone `</pre>` is not changed because treating type-1 closing tags as HTML blocks regressed CommonMark example 148. |
| case-001.md | lazy-list-tab-precedence | Accepted exception: league keeps a same-line nested item paragraph open across a tab-indented marker; Alto starts a sibling list and remains spec-green. |
| case-002.md | lazy-blockquote-list-precedence | Accepted exception: league nests a borderline block quote in a list item whose content indent is not reached; Alto closes to the root container. |
| case-003.md | fenced-code-tab-blank-in-list | Accepted exception: league normalizes a tab-only fenced-code content line inside a list to blank; Alto preserves the tab content. |
| case-004.md | html-virtual-indent | Accepted exception: one-column virtual indent before an HTML comment differs after list and block quote marker interaction. |
| case-005.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-006.md | inline-link-destination-newline | Accepted exception: escaped newline in an inline destination differs from league's normalization. |
| case-007.md | list-tab-code-vs-paragraph | Accepted exception: nested empty list plus tab-indented content differs on whether the line is code or paragraph content. |
| case-008.md | list-tab-code-vs-paragraph | Accepted exception: blank line plus tab-indented ordered-list continuation differs on code versus paragraph content. |
| case-009.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-010.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-011.md | lazy-list-marker-precedence | Accepted exception: borderline ordered-list marker after a list item is parsed as a sibling by Alto and lazy text by league. |
| case-012.md | html-block-blank-line | Accepted exception: PHP processing-instruction HTML block blank-line retention differs under a list item. |
| case-013.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-014.md | list-tab-marker-precedence | Accepted exception: nested marker-only lists with a tab continuation differ on whether trailing text belongs to the new item. |
| case-015.md | fenced-info-after-tab-marker | Accepted exception: league drops info text on an unclosed fence opened after a tab-indented empty item; Alto records the info string. |
| case-016.md | html-virtual-indent | Accepted exception: raw HTML block content after tab-indented list markers differs by virtual indentation spaces. |
| case-017.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-018.md | fenced-list-lazy-precedence | Accepted exception: unclosed fence after a nested list item differs on whether the fence belongs to the nested item or outer item. |
| case-019.md | reference-definition-title | Accepted exception: single-quote destination/title ambiguity differs from league; reference-definition examples remain 27/27. |
| case-020.md | reference-definition-title | Accepted exception: multiline reference title ambiguity differs from league; reference-definition examples remain 27/27. |
| case-021.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-022.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-023.md | html-virtual-indent | Accepted exception: HTML comment indentation after nested ordered-list marker differs by virtual indentation spaces. |
| case-024.md | list-tab-marker-precedence | Accepted exception: repeated nested marker-only lists differ on sibling versus deeper child placement. |
| case-025.md | html-virtual-indent | Accepted exception: HTML comment indentation after nested ordered-list marker differs by virtual indentation spaces. |
| case-026.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-027.md | lazy-list-marker-precedence | Accepted exception: borderline bullet marker after a list item is parsed as a sibling by Alto and lazy text by league. |
| case-028.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-029.md | html-virtual-indent | Accepted exception: raw HTML block content after nested list markers differs by virtual indentation spaces. |
| case-030.md | list-tab-marker-precedence | Accepted exception: tab continuation after nested marker-only lists differs on whether text belongs to the newest item. |
| case-031.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-032.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-033.md | reference-definition-title | Accepted exception: adjacent reference definitions on one line differ from league's permissive parsing. |
| case-034.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-035.md | reference-definition-title | Accepted exception: multiline reference title ambiguity differs from league; reference-definition examples remain 27/27. |
| case-036.md | lazy-thematic-list-precedence | Accepted exception: thematic break under a borderline list continuation differs on root versus list-item ownership. |
| case-037.md | list-tab-code-vs-list | Accepted exception: tab-indented marker after nested list content differs on indented code versus child list. |
| case-038.md | list-tab-marker-precedence | Accepted exception: tab continuation after nested marker-only lists differs on whether trailing text belongs to the newest item. |
| case-039.md | list-tab-code-indent | Accepted exception: double-tab content inside nested list item differs by two virtual spaces in indented code. |
| case-040.md | fenced-info-after-tab-marker | Accepted exception: league drops info text on an unclosed fence opened after a tab-indented empty item; Alto records the info string. |
| case-041.md | html-type1-closing-tag | Accepted exception: standalone `</script>` after a closed fence follows the same type-1 closing-tag policy as case-000. |
| case-042.md | emphasis-delimiter-tie | Accepted exception: delimiter resolution tie outside spec corpus; CommonMark emphasis section remains 132/132. |
| case-043.md | fenced-info-after-tab-marker | Accepted exception: league drops info text on an unclosed fence opened after a tab-indented empty item; Alto records the info string. |
| case-044.md | lazy-blockquote-list-precedence | Accepted exception: borderline block quote after a nested list item differs on inner versus outer ownership. |
