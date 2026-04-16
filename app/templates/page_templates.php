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

$referencingBrowseQuickLinks = '<span class="ctr-quick-links-label">Quick links</span>'
  . '<div class="ctr-quick-links">'
  . '<a href="#">Category link 1</a><a href="#">Category link 2</a><a href="#">Category link 3</a><a href="#">Category link 4</a>'
  . '<a href="#">Category link 5</a><a href="#">Category link 6</a><a href="#">Category link 7</a><a href="#">Category link 8</a>'
  . '</div>'
  . '<div class="ctr-collapse-all"><a href="#">Collapse all sections</a></div>';

$makeReferencingBrowseAccordion = static function(string $id, int $subItemCount = 5): array {
  $subItems = [];
  for ($i = 0; $i < $subItemCount; $i++) {
    $subItems[] = ['label' => 'Browse link ' . ($i + 1), 'url' => '#'];
  }

  return [
    'id' => $id,
    'type' => 'accordionTabs',
    'props' => [
      'mode' => 'accordion',
      'accStyle' => 'grouped',
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
      'items' => [[
        'id' => $id . '_item',
        'title' => 'Browse section',
        'body' => '',
        'bodyHtml' => '',
        'openDefault' => false,
        'headerImg' => '',
        'headerAlt' => '',
        'showHeaderImg' => false,
        'subItems' => $subItems,
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
            $makeReferencingBrowseAccordion('rb_acc_1', 7),
            $makeReferencingBrowseAccordion('rb_acc_2', 4),
            $makeReferencingBrowseAccordion('rb_acc_3', 6),
            $makeReferencingBrowseAccordion('rb_acc_4', 5),
            $makeReferencingBrowseAccordion('rb_acc_5', 4),
            $makeReferencingBrowseAccordion('rb_acc_6', 7),
            $makeReferencingBrowseAccordion('rb_acc_7', 4),
            $makeReferencingBrowseAccordion('rb_acc_8', 5),
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
        ['span'=>12,'blocks'=>[[
          'id'=>'st-breadcrumbs',
          'type'=>'text',
          'props'=>[
            'html'=>'<div style="font-size:14px;color:#294a97;">Home &gt; Referencing Styles &gt; Harvard &gt; Digital and Internet &gt; Blogs</div>'
          ],
          'style'=>['marginBottom'=>'10px']
        ]]]
      ]],
      ['cols'=>[
        ['span'=>3,'blocks'=>[
          [
            'id'=>'st-sidebar-style',
            'type'=>'panel',
            'props'=>[
              'image'=>'',
              'alt'=>'',
              'layout'=>'text-top',
              'splitRatio'=>'50-50',
              'bodyHtml'=>'<h3 style="margin:0 0 18px;font-size:20px;letter-spacing:.02em;">REFERENCING STYLES</h3><div style="border:1px solid rgba(17,24,39,.14);border-radius:999px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;font-size:16px;"><span>Harvard</span><span aria-hidden="true">⌄</span></div>',
              'body'=>'Referencing styles'
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'padding'=>'20px',
              'border'=>'1px solid rgba(17,24,39,.12)',
              'borderTop'=>'4px solid #294a97',
              'boxShadow'=>'none',
              'marginBottom'=>'18px'
            ]
          ],
          [
            'id'=>'st-sidebar-guidance',
            'type'=>'panel',
            'props'=>[
              'image'=>'',
              'alt'=>'',
              'layout'=>'text-top',
              'splitRatio'=>'50-50',
              'bodyHtml'=>'<h3 style="margin:0 0 18px;font-size:20px;letter-spacing:.02em;">HARVARD GUIDANCE</h3><div style="display:grid;gap:0;"><div style="padding:0 0 16px;border-bottom:1px dashed rgba(17,24,39,.14);margin-bottom:16px;">Setting out citations</div><div style="padding:0 0 16px;border-bottom:1px dashed rgba(17,24,39,.14);margin-bottom:16px;">What to include in your reference list</div><div style="padding:0 0 16px;border-bottom:1px dashed rgba(17,24,39,.14);margin-bottom:16px;">Elements that you may need to include in your references</div><div style="padding:0 0 16px;border-bottom:1px dashed rgba(17,24,39,.14);margin-bottom:16px;">Sample text and reference list using the Harvard style</div><div>Setting out quotations (Harvard)<br><span style="display:inline-block;margin-top:10px;color:#294a97;">View More</span></div></div>',
              'body'=>'Style guidance'
            ],
            'style'=>[
              'backgroundColor'=>'#ffffff',
              'padding'=>'20px',
              'border'=>'1px solid rgba(17,24,39,.12)',
              'borderTop'=>'4px solid #294a97',
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
              'title'=>'Blogs (Harvard)',
              'body'=>'Blogs (weblogs) and vlogs (video logs) are produced by individuals and organisations to provide updates on issues of interest or concern. Be aware that because blogs/vlogs are someone\'s opinions, they may not provide objective, reasoned discussion of an issue. Use blogs/vlogs in conjunction with reputable sources. Note that due to the informality of the internet, many authors give first names or aliases. Use the name they have used in your reference.',
              'bgImage'=>'',
              'bgColor'=>'#d9d1c2',
              'overlayOpacity'=>0
            ],
            'style'=>[
              'marginBottom'=>'18px',
              'boxShadow'=>'none',
              'border'=>'0'
            ],
            'styleText'=>['color'=>'#111827','fontSize'=>16]
          ],
          [
            'id'=>'st-citation-order',
            'type'=>'citationOrder',
            'props'=>[
              'title'=>'Citation order:',
              'body'=>"• Author of message\n• Year the site was last updated (in round brackets)\n• Title of blog post (in single quotation marks)\n• Title of internet site (in italics)\n• Day/month of posted message\n• Available at: URL (Accessed: date)"
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
        ]]
      ]],
    ],
  ],
  'referencing-browse' => $referencingBrowse,
];
