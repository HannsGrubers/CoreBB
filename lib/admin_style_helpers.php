<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 |                    .oooO                              |
 |                    (   )   Oooo.                      |
 +---------------------\ (----(   )----------------------+
                        \_)    ) /
                              (_/

 +-------------------------------------------------------+
 |  admin_style_helpers.php  - Shared VN-style admin     |
 |  chrome and typography reset.                         |
 +-------------------------------------------------------+*/

/**
 * Usage: Print the legacy admin font reset when old pages need it.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_admin_print_font_reset(): void
{
    static $printed = false;
    if($printed){
        return;
    }
    $printed = true;
    ?>
<style type="text/css">
/* Keep the admin area on the old dense VN/control-panel rhythm. */
.wb-v2-admin-shell,
.wb-v2-admin-shell td,
.wb-v2-admin-shell th,
.wb-v2-admin-shell div,
.wb-v2-admin-shell span,
.wb-v2-admin-shell p,
.wb-v2-admin-shell a,
.wb-v2-admin-shell input,
.wb-v2-admin-shell select,
.wb-v2-admin-shell textarea,
.wb-v2-admin-shell button {
    font-family: verdana, arial, sans-serif !important;
    font-size: 10pt;
    line-height: normal;
}

.wb-v2-admin-shell {
    margin: 0;
}

.wb-v2-admin-sidebar {
    width: 250px;
    white-space: nowrap;
    vertical-align: top;
    padding: 15px !important;
    color: black;
}

.wb-v2-admin-sidebar strong {
    font-weight: bold;
    display: inline;
}

.wb-v2-admin-sidebar p {
    margin: 0 0 15px 0;
    padding: 0;
}

.wb-v2-admin-sidebar a,
.wb-v2-admin-sidebar a:link,
.wb-v2-admin-sidebar a:visited,
.wb-v2-admin-sidebar .BoardRowALink,
.wb-v2-admin-sidebar .BoardRowBLink,
.wb-v2-admin-sidebar .MultiPages {
    color: blue !important;
    text-decoration: none !important;
    font-weight: normal !important;
}

.wb-v2-admin-sidebar a:hover {
    text-decoration: underline !important;
}

.wb-v2-admin-content {
    vertical-align: top;
    padding: 15px !important;
    color: black !important;
}

.wb-v2-admin-content table {
    border-collapse: separate;
}

.wb-v2-admin-content .BoardColumn,
.wb-v2-admin-content tr.BoardColumn > td,
.wb-v2-admin-content tr.BoardColumn > th,
.wb-v2-admin-content td.BoardColumn,
.wb-v2-admin-content th.BoardColumn,
.wb-v2-admin-shell .BoardColumn,
.wb-v2-admin-shell tr.BoardColumn > td,
.wb-v2-admin-shell tr.BoardColumn > th,
.wb-v2-admin-shell td.BoardColumn,
.wb-v2-admin-shell th.BoardColumn {
    background-color: #666666 !important;
    color: white !important;
    font-weight: bold !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content tr.BoardColumn > td *,
.wb-v2-admin-content tr.BoardColumn > th *,
.wb-v2-admin-content td.BoardColumn *,
.wb-v2-admin-content th.BoardColumn * {
    color: inherit !important;
    font-weight: inherit !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content .BoardColumnLink,
.wb-v2-admin-content a.BoardColumnLink {
    color: orange !important;
    font-weight: bold !important;
    font-size: 10pt !important;
    text-decoration: none !important;
}

.wb-v2-admin-content .BoardRowA,
.wb-v2-admin-content tr.BoardRowA > td,
.wb-v2-admin-content tr.BoardRowA > th,
.wb-v2-admin-content td.BoardRowA,
.wb-v2-admin-content th.BoardRowA,
.wb-v2-admin-shell .BoardRowA,
.wb-v2-admin-shell tr.BoardRowA > td,
.wb-v2-admin-shell tr.BoardRowA > th,
.wb-v2-admin-shell td.BoardRowA,
.wb-v2-admin-shell th.BoardRowA {
    background-color: #b1b3bc !important;
    color: black !important;
    font-weight: normal !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content .BoardRowB,
.wb-v2-admin-content tr.BoardRowB > td,
.wb-v2-admin-content tr.BoardRowB > th,
.wb-v2-admin-content td.BoardRowB,
.wb-v2-admin-content th.BoardRowB,
.wb-v2-admin-shell .BoardRowB,
.wb-v2-admin-shell tr.BoardRowB > td,
.wb-v2-admin-shell tr.BoardRowB > th,
.wb-v2-admin-shell td.BoardRowB,
.wb-v2-admin-shell th.BoardRowB {
    background-color: #c1c2c9 !important;
    color: black !important;
    font-weight: normal !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content b,
.wb-v2-admin-content strong,
.wb-v2-admin-content .InputSection,
.wb-v2-admin-shell b,
.wb-v2-admin-shell strong,
.wb-v2-admin-shell .InputSection {
    color: black !important;
    font-weight: bold !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content .BoardColumn b,
.wb-v2-admin-content .BoardColumn strong,
.wb-v2-admin-content .BoardColumn .InputSection,
.wb-v2-admin-content tr.BoardColumn > td b,
.wb-v2-admin-content tr.BoardColumn > td strong,
.wb-v2-admin-content tr.BoardColumn > td .InputSection,
.wb-v2-admin-content tr.BoardColumn > th b,
.wb-v2-admin-content tr.BoardColumn > th strong,
.wb-v2-admin-content tr.BoardColumn > th .InputSection {
    color: inherit !important;
}

/* Ordinary admin links are blue, but inline-styled usernames keep their colors. */
.wb-v2-admin-content a:not([style]),
.wb-v2-admin-content a:not([style]):link,
.wb-v2-admin-content a:not([style]):visited,
.wb-v2-admin-content .BoardRowALink:not([style]),
.wb-v2-admin-content .BoardRowBLink:not([style]),
.wb-v2-admin-content .SubjectLink:not([style]),
.wb-v2-admin-content .AuthorLink:not([style]) {
    color: blue !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content a[style],
.wb-v2-admin-content a[style]:link,
.wb-v2-admin-content a[style]:visited,
.wb-v2-admin-content .AuthorLink[style] {
    font-size: 10pt !important;
}

.wb-v2-admin-content .SmallText,
.wb-v2-admin-content .SmallText *,
.wb-v2-admin-content a.SmallText,
.wb-v2-admin-content span.SmallText,
.wb-v2-admin-content div.SmallText,
.wb-v2-admin-content td.SmallText,
.wb-v2-admin-shell .SmallText,
.wb-v2-admin-shell .SmallText * {
    font-family: verdana, arial, sans-serif !important;
    font-size: 7pt !important;
    font-weight: normal !important;
    color: black !important;
}

.wb-v2-admin-content .MediumText,
.wb-v2-admin-content .MediumText * {
    font-size: 9pt !important;
}

.wb-v2-admin-content .LargeText,
.wb-v2-admin-content .LargeText * {
    font-size: 10pt !important;
}

.wb-v2-admin-content h1,
.wb-v2-admin-content h2,
.wb-v2-admin-content h3,
.wb-v2-admin-content h4,
.wb-v2-admin-content h5,
.wb-v2-admin-content h6 {
    margin: 0 !important;
    padding: 0 !important;
    font-family: verdana, arial, sans-serif !important;
    font-size: 10pt !important;
    font-weight: bold !important;
}

.wb-v2-admin-content input,
.wb-v2-admin-content select,
.wb-v2-admin-content textarea,
.wb-v2-admin-content button {
    font-family: verdana, arial, sans-serif !important;
    font-size: 10pt !important;
}

.wb-v2-admin-content input[type="submit"],
.wb-v2-admin-content input[type="button"],
.wb-v2-admin-content button,
.wb-v2-admin-content .formsubmit {
    background: #c1c2c9 !important;
    color: black !important;
    font-family: verdana, arial, sans-serif !important;
    font-size: 10px !important;
    font-weight: bold !important;
    border: 1px inset #999999 !important;
}

.wb-v2-admin-content p {
    margin-top: 0.6em !important;
    margin-bottom: 0.6em !important;
}

.wb-v2-admin-content fieldset {
    border: 1px solid #666666;
    margin: 0 0 12px 0;
    padding: 8px;
}

.wb-v2-admin-content legend {
    font-weight: bold;
}

.wb-v2-admin-content table.AdminActions {
    background-color: #c1c2c9 !important;
    color: black !important;
    border: 0 !important;
}

.wb-v2-admin-content table.AdminActions td {
    padding-top: 2px !important;
    padding-bottom: 2px !important;
}
</style>
<?php
}
