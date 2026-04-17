<?php

$titlePageBlockStyle = [
  'marginBottom' => '18px',
  'boxShadow' => 'none',
];

$titlePage = [
  'version' => 1,
  'page' => [
    'backgroundColor' => '#ffffff',
    'textColor' => '#333333',
  ],
  'rows' => [
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'cols' => [
        [
          'span' => 12,
          'blocks' => [
            [
              'id' => 'tp-hero',
              'type' => 'heroBanner',
              'style' => $titlePageBlockStyle,
              'props' => [
                'heading' => 'Hero heading',
                'cta' => 'Primary action',
                'bgImage' => '',
                'overlayOpacity' => 0.75,
              ],
            ],
          ],
        ],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        [
          'span' => 4,
          'blocks' => [
            [
              'id' => 'tp-feature-1',
              'type' => 'card',
              'props' => [
                'title' => 'Feature tile 1',
                'body' => 'Add a short title-led promo panel here.',
              ],
              'style' => [
                'marginBottom' => '18px',
                'boxShadow' => 'none',
                'minHeight' => '138px',
              ],
              'styleText' => ['align' => 'center'],
            ],
          ],
        ],
        [
          'span' => 4,
          'blocks' => [
            [
              'id' => 'tp-feature-2',
              'type' => 'card',
              'props' => [
                'title' => 'Feature tile 2',
                'body' => 'Add a second promo panel here.',
              ],
              'style' => [
                'marginBottom' => '18px',
                'boxShadow' => 'none',
                'minHeight' => '138px',
              ],
              'styleText' => ['align' => 'center'],
            ],
          ],
        ],
        [
          'span' => 4,
          'blocks' => [
            [
              'id' => 'tp-sidebar',
              'type' => 'text',
              'props' => [
                'html' => '<h2 style="margin:0 0 12px">Sidebar feature</h2><p style="margin:0 0 12px">Add supporting copy for the right-hand panel.</p><ul style="margin:0;padding-left:18px"><li>Editable link item</li></ul>',
              ],
              'style' => [
                'marginBottom' => '18px',
                'boxShadow' => 'none',
                'backgroundColor' => '#20366f',
                'padding' => '22px 20px',
                'minHeight' => '292px',
                'borderRadius' => '16px',
              ],
              'styleText' => ['color' => '#ffffff'],
            ],
          ],
        ],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'cols' => [
        [
          'span' => 12,
          'blocks' => [
            [
              'id' => 'tp-searches-heading',
              'type' => 'heading',
              'style' => $titlePageBlockStyle,
              'props' => ['level' => 2, 'text' => 'Section heading'],
              'styleText' => ['align' => 'center'],
            ],
          ],
        ],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-1',
          'type' => 'card',
          'props' => ['title' => 'Search tile 1', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-2',
          'type' => 'card',
          'props' => ['title' => 'Search tile 2', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-3',
          'type' => 'card',
          'props' => ['title' => 'Search tile 3', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-4',
          'type' => 'card',
          'props' => ['title' => 'Search tile 4', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-5',
          'type' => 'card',
          'props' => ['title' => 'Search tile 5', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
        ['span' => 4, 'blocks' => [[
          'id' => 'tp-search-6',
          'type' => 'card',
          'props' => ['title' => 'Search tile 6', 'body' => 'Add search category content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '168px'],
          'styleText' => ['align' => 'center'],
        ]]],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'cols' => [
        [
          'span' => 12,
          'blocks' => [
            [
              'id' => 'tp-highlights-heading',
              'type' => 'heading',
              'style' => $titlePageBlockStyle,
              'props' => ['level' => 2, 'text' => 'Section heading'],
              'styleText' => ['align' => 'center'],
            ],
          ],
        ],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-1',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 1', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-2',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 2', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-3',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 3', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-4',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 4', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'equalHeight' => true,
      'cols' => [
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-5',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 5', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
        ['span' => 6, 'blocks' => [[
          'id' => 'tp-highlight-6',
          'type' => 'card',
          'props' => ['title' => 'Highlight item 6', 'body' => 'Add highlight summary content.'],
          'style' => ['marginBottom' => '18px', 'boxShadow' => 'none', 'minHeight' => '150px'],
        ]]],
      ],
    ],
    [
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'cols' => [
        [
          'span' => 12,
          'blocks' => [
            [
              'id' => 'tp-footer-banner',
              'type' => 'text',
              'props' => [
                'html' => '<h3 style="margin:0 0 10px">Footer banner</h3><p style="margin:0">Use this space for a final homepage callout or supporting message.</p>',
              ],
              'style' => [
                'marginBottom' => '18px',
                'boxShadow' => 'none',
                'backgroundColor' => '#f5f5f5',
                'padding' => '22px 24px',
                'borderRadius' => '16px',
              ],
              'styleText' => ['align' => 'center'],
            ],
          ],
        ],
      ],
    ],
  ],
];

$referencingBrowseSections = [
  'rb_acc_books' => 'Books',
  'rb_acc_journals' => 'Journals',
  'rb_acc_digital_internet' => 'Digital and Internet',
  'rb_acc_media_art' => 'Media and Art',
  'rb_acc_research' => 'Research',
  'rb_acc_legal' => 'Legal',
  'rb_acc_governmental' => 'Governmental',
  'rb_acc_communications' => 'Communications',
];

$quickLinkItems = [];
foreach ($referencingBrowseSections as $sectionId => $label) {
  $quickLinkItems[] = '<a href="#' . htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

$referencingBrowseQuickLinks = '<div class="ctr-quick-links-shell" style="padding:6px 0 14px;">'
  . '<div class="ctr-quick-links-label" style="font-weight:700;margin-bottom:10px;">Quick Links</div>'
  . '<div class="ctr-quick-links" style="display:flex;flex-wrap:wrap;gap:0;">'
  . implode('<span style="color:#c7ccd6;padding:0 10px;">|</span>', $quickLinkItems)
  . '</div>'
  . '</div>';

$makeReferencingBrowseAccordion = static function(string $id, string $title): array {

  return [
    'id' => $id,
    'type' => 'accordionTabs',
    'props' => [
      'mode' => 'accordion',
      'accStyle' => 'title',
      'allowMultiple' => true,
      'allowCollapseAll' => true,
      'defaultOpen' => 'none',
      'defaultIndex' => 0,
      'tabsDefault' => 'first',
      'tabsIndex' => 0,
      'tabsAlign' => 'left',
      'tabsStyle' => 'underline',
      'styleVariant' => 'minimal',
      'showDividers' => true,
      'showIndicator' => true,
      'indicatorPosition' => 'right',
      'spacing' => 'compact',
      'headerImgPos' => 'left',
      'headerImgSize' => 'medium',
      'showBorder' => true,
      'headerBg' => '#f2f2f1',
      'items' => [[
        'id' => $id . '_item',
        'title' => $title,
        'body' => '',
        'bodyHtml' => '',
        'openDefault' => false,
        'headerImg' => '',
        'headerAlt' => '',
        'showHeaderImg' => false,
        'subItems' => [],
      ]],
    ],
    'style' => [
      'marginBottom' => '10px',
    ],
  ];
};

$makeReferencingBrowsePanel = static function(string $id, string $label): array {
  return [
    'id' => $id,
    'type' => 'panel',
    'props' => [
      'image' => '',
      'alt' => '',
      'layout' => 'img-top',
      'splitRatio' => '50-50',
      'bodyHtml' => $label,
      'body' => $label,
    ],
    'style' => [
      'marginBottom' => '6px',
    ],
    'styleText' => [
      'fontSize' => 12,
      'color' => '#1f2f55',
    ],
  ];
};

$referencingBrowse = [
  'version' => 1,
  'rows' => [
    [
      'cols' => [[
        'span' => 12,
        'blocks' => [[
          'id' => 'rb_hero',
          'type' => 'heroCard',
          'props' => [
            'title' => 'Browse page title',
            'body' => 'Add a short introduction for this referencing browse page.',
            'bgImage' => '',
            'bgColor' => '#111827',
            'overlayOpacity' => 0.3,
          ],
          'styleText' => [
            'fontSize' => 16,
            'color' => '#ffffff',
          ],
        ]],
      ]],
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'collapsed' => false,
      'equalHeight' => false,
    ],
    [
      'cols' => [[
        'span' => 12,
        'blocks' => [[
          'id' => 'rb_quick_links',
          'type' => 'text',
          'props' => [
            'text' => 'Quick links',
            'html' => $referencingBrowseQuickLinks,
          ],
          'styleText' => [
            'fontSize' => 14,
            'color' => '#1f2937',
          ],
        ]],
      ]],
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'collapsed' => false,
      'equalHeight' => false,
    ],
    [
      'cols' => [
        [
          'span' => 8,
          'blocks' => [
            $makeReferencingBrowseAccordion('rb_acc_books', 'Books'),
            $makeReferencingBrowseAccordion('rb_acc_journals', 'Journals'),
            $makeReferencingBrowseAccordion('rb_acc_digital_internet', 'Digital and Internet'),
            $makeReferencingBrowseAccordion('rb_acc_media_art', 'Media and Art'),
            $makeReferencingBrowseAccordion('rb_acc_research', 'Research'),
            $makeReferencingBrowseAccordion('rb_acc_legal', 'Legal'),
            $makeReferencingBrowseAccordion('rb_acc_governmental', 'Governmental'),
            $makeReferencingBrowseAccordion('rb_acc_communications', 'Communications'),
          ],
        ],
        [
          'span' => 4,
          'blocks' => [
            [
              'id' => 'rb_sidebar_heading',
              'type' => 'heading',
              'props' => [
                'level' => 3,
                'text' => 'General guidance',
              ],
              'styleText' => [
                'fontSize' => 18,
                'color' => '#334155',
              ],
              'style' => [
                'marginBottom' => '8px',
              ],
            ],
            $makeReferencingBrowsePanel('rb_panel_1', 'Guidance panel 1'),
            $makeReferencingBrowsePanel('rb_panel_2', 'Guidance panel 2'),
            $makeReferencingBrowsePanel('rb_panel_3', 'Guidance panel 3'),
            $makeReferencingBrowsePanel('rb_panel_4', 'Guidance panel 4'),
            $makeReferencingBrowsePanel('rb_panel_5', 'Guidance panel 5'),
            $makeReferencingBrowsePanel('rb_panel_6', 'Guidance panel 6'),
            [
              'id' => 'rb_view_more',
              'type' => 'text',
              'props' => [
                'text' => 'View more articles',
                'html' => '<a class="ctr-view-more" href="#">View more articles</a>',
              ],
              'style' => [
                'marginTop' => '10px',
              ],
              'styleText' => [
                'fontSize' => 14,
                'color' => '#ffffff',
              ],
            ],
          ],
        ],
      ],
      'styleRow' => ['bgEnabled' => false, 'bgColor' => ''],
      'collapsed' => false,
      'equalHeight' => false,
    ],
  ],
];

return [
  'home' => [
    'version' => 1,
    'rows' => [
      ['cols' => [
        ['span'=>12,'blocks'=>[
          ['id'=>'h1','type'=>'heading','props'=>['level'=>1,'text'=>'Home']],
          ['id'=>'t1','type'=>'text','props'=>['text'=>'Starter home layout. Drag blocks in the builder to customise.']]
        ]]
      ]],
      ['cols' => [
        ['span'=>8,'blocks'=>[
          ['id'=>'c1','type'=>'card','props'=>['title'=>'Primary Panel','body'=>'Main content area.']]
        ]],
        ['span'=>4,'blocks'=>[
          ['id'=>'c2','type'=>'card','props'=>['title'=>'Sidebar','body'=>'Secondary content area.']]
        ]]
      ]]
    ]
  ],
  'title-page' => $titlePage,
  'title-page-1' => $titlePage,
  'landing' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>12,'blocks'=>[
          ['id'=>'lh','type'=>'heading','props'=>['level'=>1,'text'=>'Landing Page']],
          ['id'=>'lt','type'=>'text','props'=>['text'=>'Use this template for marketing/CTA pages.']]
        ]]
      ]],
      ['cols'=>[
        ['span'=>4,'blocks'=>[['id'=>'f1','type'=>'card','props'=>['title'=>'Feature 1','body'=>'Describe a feature.']]]],
        ['span'=>4,'blocks'=>[['id'=>'f2','type'=>'card','props'=>['title'=>'Feature 2','body'=>'Describe a feature.']]]],
        ['span'=>4,'blocks'=>[['id'=>'f3','type'=>'card','props'=>['title'=>'Feature 3','body'=>'Describe a feature.']]]],
      ]]
    ]
  ],
  'article' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>12,'blocks'=>[['id'=>'ah','type'=>'heading','props'=>['level'=>1,'text'=>'Article Title']]]]
      ]],
      ['cols'=>[
        ['span'=>8,'blocks'=>[['id'=>'ab','type'=>'text','props'=>['text'=>'Write the article body here.']]]],
        ['span'=>4,'blocks'=>[['id'=>'ar','type'=>'card','props'=>['title'=>'Related','body'=>'Add links or widgets here.']]]]
      ]]
    ]
  ],
  'landing-lite' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>12,'blocks'=>[
          ['type'=>'heroCard','props'=>[
            'title'=>'Build something great',
            'body'=>'A focused hero with CTA-ready layout.',
            'bgColor'=>'#14532d',
            'overlayOpacity'=>0.25
          ]],
        ]]
      ]],
      ['cols'=>[
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Feature one','body'=>'Explain a key value.']]]],
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Feature two','body'=>'Show a benefit with detail.']]]],
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Feature three','body'=>'Add a concise proof point.']]]],
      ]],
    ],
  ],
  'resource-library' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>12,'blocks'=>[
          ['type'=>'heading','props'=>['level'=>2,'text'=>'Resource library']],
          ['type'=>'text','props'=>['text'=>'Introduce the library and how to use it.']],
        ]]
      ]],
      ['cols'=>[
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Resource A','body'=>'Summary and link.']]]],
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Resource B','body'=>'Summary and link.']]]],
        ['span'=>4,'blocks'=>[['type'=>'card','props'=>['title'=>'Resource C','body'=>'Summary and link.']]]],
      ]],
      ['cols'=>[
        ['span'=>12,'blocks'=>[['type'=>'divider','props'=>[]]]],
      ]],
    ],
  ],
  'about-profile' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>6,'blocks'=>[
          ['type'=>'heroCard','props'=>[
            'title'=>'About our team',
            'body'=>'Use this area for a concise profile or story.',
            'bgColor'=>'#1d4ed8',
            'overlayOpacity'=>0.3
          ]],
        ]],
        ['span'=>6,'blocks'=>[
          ['type'=>'text','props'=>['text'=>"Who we are\n\nTell your story, mission, and values.\n\nKey highlights:\n• Achievement one\n• Achievement two\n• Achievement three"]],
        ]],
      ]],
      ['cols'=>[
        ['span'=>12,'blocks'=>[
          ['type'=>'citationOrder','props'=>[
            'title'=>'Key facts',
            'body'=>"• Founded: 2010\n• Offices: Remote-first\n• Focus: Experience-led CMS"
          ]],
        ]]
      ]],
    ],
  ],
  'source-type' => [
    'version' => 1,
    'rows' => [
      ['cols'=>[
        ['span'=>3,'blocks'=>[
          [
            'id'=>'st-links-primary',
            'type'=>'linkList',
            'props'=>[
              'title'=>'Link list 1',
              'items'=>[
                ['title'=>'Link item 1','subtitle'=>'','url'=>'#'],
                ['title'=>'Link item 2','subtitle'=>'','url'=>'#'],
                ['title'=>'Link item 3','subtitle'=>'','url'=>'#'],
              ],
              'footerLabel'=>'',
              'footerUrl'=>''
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'padding'=>'0',
              'boxShadow'=>'none',
              'marginBottom'=>'18px'
            ]
          ],
          [
            'id'=>'st-links-secondary',
            'type'=>'linkList',
            'props'=>[
              'title'=>'Link list 2',
              'items'=>[
                ['title'=>'Link item 1','subtitle'=>'','url'=>'#'],
                ['title'=>'Link item 2','subtitle'=>'','url'=>'#'],
                ['title'=>'Link item 3','subtitle'=>'','url'=>'#'],
              ],
              'footerLabel'=>'',
              'footerUrl'=>''
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'padding'=>'0',
              'boxShadow'=>'none',
              'marginBottom'=>'18px'
            ]
          ],
        ]],
        ['span'=>9,'blocks'=>[
          [
            'id'=>'st-hero',
            'type'=>'heroCard',
            'props'=>[
              'title'=>'Source type heading',
              'body'=>'Add the source-type overview here.',
              'bgImage'=>'',
              'bgColor'=>'#f4f0e7',
              'overlayOpacity'=>0
            ],
            'style'=>[
              'marginBottom'=>'18px',
              'boxShadow'=>'none',
              'border'=>'1px solid rgba(17,24,39,.08)',
              'borderRadius'=>'18px'
            ],
            'styleText'=>['color'=>'#111827','fontSize'=>16]
          ],
          [
            'id'=>'st-citation-order',
            'type'=>'citationOrder',
            'props'=>[
              'title'=>'Citation order:',
              'body'=>"• Author / organisation\n• Year of publication\n• Title\n• Source details\n• URL or DOI (if needed)\n• Accessed date (if needed)"
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'padding'=>'18px 20px',
              'border'=>'1px solid rgba(17,24,39,.12)',
              'borderRadius'=>'16px',
              'boxShadow'=>'none',
              'marginBottom'=>'18px'
            ]
          ],
          [
            'id'=>'st-example',
            'type'=>'exampleCard',
            'props'=>[
              'heading'=>'Examples',
              'body'=>"**In-text citations**\n\nAdd worked in-text citation examples here.\n\n**Reference list**\n\nAdd matching reference list examples here.",
              'youTry'=>"Author / organisation (Year) Title. Source details. URL or DOI (Accessed: date)."
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'borderTop'=>'4px solid #c8b7e8',
              'borderRadius'=>'16px',
              'padding'=>'0',
              'boxShadow'=>'none',
              'marginBottom'=>'18px'
            ]
          ],
        ]]
      ]],
    ],
  ],
  'referencing-browse' => $referencingBrowse,
];
