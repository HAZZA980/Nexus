<?php
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
        ['span'=>12,'blocks'=>[
          ['type'=>'heading','props'=>['level'=>1,'text'=>'Source type']],
          ['type'=>'text','props'=>['text'=>"Use this page to document a specific source type.\nAdd guidance and examples below."]],
          ['type'=>'citationOrder','props'=>[
            'title'=>'Citation order',
            'body'=>"1. Author / editor\n2. Year (round brackets)\n3. Title (italics)\n4. Publisher\n5. DOI or URL (Accessed: date)"
          ]],
          ['type'=>'exampleCard','props'=>[
            'exampleId'=>'book_one_author',
            'heading'=>'Example: book with one author',
            'body'=>"In-text citations\n\n(Author, Year, p. 00)\n\nReference list\nAuthor, A. (Year) *Title of work.* Publisher.",
            'youTry'=>'Surname, Initial. (Year) *Title.* Publisher.',
            'showYouTry'=>true
          ]],
        ]]
      ]],
    ],
  ],
];
