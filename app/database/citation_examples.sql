-- Citation examples database for Cite Them Right
-- Populates citation order, example, you-try text, and editor notes.

CREATE TABLE IF NOT EXISTS citation_examples (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_slug VARCHAR(190) NOT NULL,
  referencing_style VARCHAR(100) NOT NULL DEFAULT 'Harvard',
  category VARCHAR(80) NOT NULL DEFAULT 'Books',
  sub_category VARCHAR(120) NULL,
  example_key VARCHAR(190) NOT NULL,
  label VARCHAR(190) NOT NULL,
  citation_order TEXT NOT NULL,
  example_heading VARCHAR(255) NOT NULL,
  example_body TEXT NOT NULL,
  you_try TEXT NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_example (site_slug, example_key),
  INDEX idx_site_slug (site_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data for the cite-them-right site. Edit in SQL, not in the CMS UI.
INSERT INTO citation_examples (site_slug, referencing_style, category, sub_category, example_key, label, citation_order, example_heading, example_body, you_try, notes) VALUES
('cite-them-right','Harvard','Books',NULL,'book_one_author','Book with one author',
'Author/editor name\nYear of publication (round brackets)\nTitle in italics\nEdition (if not the first)\nPublisher\nSeries and volume number (where relevant)',
'Example: book with one author',
'In-text citations\n\nThe overview by McCormick (2023) confirms Hill''s experience (2023, pp. 46-52).\n\nNB: No page number citation for McCormick because the reference is to the whole book.\n\nSpecific pages are being cited in Hill''s book.\n\nReference list\n\nHill, F. (2023) There''s nothing for you here: finding opportunity in the twenty-first century. Mariner Books.\n\nMcCormick, J.M. (2023) American foreign policy and process. 7th edn. Cambridge University Press.',
'Surname, Initial. (Year of publication) Title. Edition. Publisher.',
'Use when a single person is credited as the author. Omit the edition if it is the first. For later editions, include the edition number before the publisher.'),

('cite-them-right','Harvard','Books',NULL,'book_two_authors','Book with two authors',
'Both authors'' surnames and initials (in the order shown on the title page)\nYear of publication in round brackets\nTitle in italics\nEdition (if not the first)\nPublisher\nSeries and volume (if relevant)',
'Example: book with two authors',
'In-text citations\n\nThompson and Reed (2022) argue that clear frameworks improve adoption.\n\nReference list\n\nThompson, A. and Reed, J. (2022) Designing research methods. 2nd edn. Sage.',
'Surname, Initial. and Surname, Initial. (Year) Title. Edition. Publisher.',
'List authors in the order they appear on the title page. Use an ampersand only in Harvard in-text citations if that is the house style; this set keeps "and" for consistency.'),

('cite-them-right','Harvard','Books',NULL,'book_three_plus','Book with three or more authors',
'First author''s surname and initials followed by "et al."\nYear of publication in round brackets\nTitle in italics\nEdition (if not the first)\nPublisher\nSeries and volume (if relevant)',
'Example: book with three or more authors',
'In-text citations\n\nGarcia et al. (2021) illustrate the impact of collaboration on outcomes.\n\nReference list\n\nGarcia, L., Patel, S., O''Neill, T. and Chen, M. (2021) Collaborative leadership in practice. Routledge.',
'Surname, Initial., Surname, Initial., Surname, Initial. and Surname, Initial. (Year) Title. Publisher.',
'Include up to four authors in the reference list before using et al. Follow your local rule if more names are present.'),

('cite-them-right','Harvard','Books',NULL,'edited_book','Edited book',
'Editor surname and initials followed by (ed.) or (eds.)\nYear of publication in round brackets\nTitle in italics\nEdition (if not the first)\nPublisher\nSeries and volume (if relevant)',
'Example: edited book',
'In-text citations\n\nTaylor (2019) discusses interdisciplinary approaches.\n\nReference list\n\nTaylor, H. (ed.) (2019) Interdisciplinary frontiers. Cambridge University Press.',
'Surname, Initial. (ed.) (Year) Title. Publisher.',
'Use (ed.) for a single editor and (eds.) for multiple editors. If there is no author, the editor moves into the author position.'),

('cite-them-right','Harvard','Books',NULL,'chapter_in_edited','Chapter in an edited book',
'Chapter author surname and initials\nYear of publication in round brackets\nTitle of chapter in single quotation marks\nIn: editor surname and initials (ed./eds.)\nTitle of book in italics\nPublisher\nPage range of chapter',
'Example: chapter in an edited book',
'In-text citations\n\nAhmed (2020) highlights local context in policy design.\n\nReference list\n\nAhmed, R. (2020) "Local context in policy design", in Gray, P. and Smith, L. (eds.) Policy in practice. Oxford University Press, pp. 45-62.',
'Chapter author Surname, Initial. (Year) "Title of chapter", in Editor Surname, Initial. (ed.) Title of book. Publisher, page numbers.',
'Keep the book title in italics; the chapter title stays in quotation marks. Include the full page span for the chapter.'),

('cite-them-right','Harvard','Books',NULL,'translated_book','Translated book',
'Author surname and initials\nYear of publication in round brackets\nTitle in italics\nTranslated by Initial. Surname\nPublisher',
'Example: translated book',
'In-text citations\n\nBenjamin (2018) explores translation theory.\n\nReference list\n\nBenjamin, C. (2018) The craft of translation. Translated by P. Long. Penguin.',
'Author Surname, Initial. (Year) Title. Translated by Initial. Surname. Publisher.',
'Credit the translator after the title. If citing the original publication date as well, include it at the end in brackets.'),

('cite-them-right','Harvard','Books',NULL,'corporate_author','Corporate author book',
'Corporate author (organisation)\nYear of publication in round brackets\nTitle in italics\nPublisher (if different from the corporate author)\nEdition (if not the first)',
'Example: corporate (organisation-authored) book',
'In-text citations\n\nWorld Health Organization (2021) provides updated guidance.\n\nReference list\n\nWorld Health Organization (2021) Global health strategies. WHO Press.',
'Organisation Name (Year) Title. Publisher.',
'Only repeat the organisation in the publisher position if it acts as its own publisher.'),

('cite-them-right','Harvard','Books',NULL,'no_author','Book with no identified author',
'Title (in italics)\nYear of publication in round brackets\nEdition (if not the first)\nPublisher',
'Example: book with no identified author',
'In-text citations\n\n(The concise atlas, 2020) is frequently consulted.\n\nReference list\n\nThe concise atlas (2020) London: Atlas Press.',
'Title (Year) Publisher.',
'Move the title into the author position. In in-text citations, shorten long titles for readability.'),

('cite-them-right','Harvard','Books',NULL,'editor_as_author','Book with an editor instead of an author',
'Editor surname and initials followed by (ed.) or (eds.)\nYear of publication in round brackets\nTitle in italics\nEdition (if not the first)\nPublisher',
'Example: book with editor instead of author',
'In-text citations\n\nLane (2017) compiles key essays on ethics.\n\nReference list\n\nLane, D. (ed.) (2017) Ethics and society. Blackwell.',
'Editor Surname, Initial. (ed.) (Year) Title. Publisher.',
'Treat the editor as the author. Swap to (eds.) for multiple editors.'),

('cite-them-right','Harvard','Books',NULL,'reprinted_book','Reprinted book',
'Author surname and initials\nYear of reprint in round brackets\nTitle in italics\nReprint statement with original year\nPublisher',
'Example: reprinted book',
'In-text citations\n\nDurkheim (2010) remains influential.\n\nReference list\n\nDurkheim, E. (2010) The rules of sociological method. Reprint of 1895 edn. Free Press.',
'Author Surname, Initial. (Year) Title. Reprint of original year edn. Publisher.',
'Give the reprint year in the date position. Mention the original publication year in the reprint statement.'),

('cite-them-right','Harvard','Books',NULL,'multi_volume','Multi-volume book',
'Author surname and initials\nYear of publication in round brackets\nTitle in italics\nTotal number of volumes\nSpecific volume number cited\nPublisher',
'Example: multi-volume book',
'In-text citations\n\nHarris (2019, vol. 2) analyses regional trends.\n\nReference list\n\nHarris, K. (2019) Economic landscapes. 3 vols. Vol. 2. Sage.',
'Author Surname, Initial. (Year) Title. Number of vols. Vol. number. Publisher.',
'State the total number of volumes and the specific volume used. Include volume numbers in both in-text and reference list entries where helpful.');
