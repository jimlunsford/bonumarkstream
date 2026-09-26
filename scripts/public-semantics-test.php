<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/_bonumark_stream/app/functions.php';
require_once dirname(__DIR__) . '/_bonumark_stream/app/renderer.php';
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function renderFixture(string $name, array $data): string {
    $bms_theme_data = $data;
    ob_start(); include dirname(__DIR__) . '/_bonumark_stream/app/views/default/templates/' . $name . '.php'; return ob_get_clean();
}
foreach (['home'=>'Stream','single'=>'Stream post','archive'=>'Archive for September 2026','following'=>'Following','search'=>'Search','profile'=>'Profile'] as $name=>$label) {
    $html = renderFixture($name, ['title'=>$name === 'archive' ? $label : '', 'theme'=>['layout_schema'=>0], 'card_html'=>'<article>Primary post</article>']);
    $doc = new DOMDocument(); @$doc->loadHTML($html);
    $main = $doc->getElementsByTagName('main')->item(0);
    check($main && $main->getAttribute('aria-label') === $label, $name . ' has a named main landmark');
    check($main->getAttribute('tabindex') === '-1', 'Skip link destination must accept focus');
}
check(bms_stream_media_alt(['title'=>'filename-style.jpg','body'=>'Repeated post excerpt']) === '', 'Post titles are not image descriptions');
check(bms_stream_media_item_alt(['title'=>'Invented description'], 'media/absent.jpg', 2, 4) === '', 'Missing metadata stays empty');
foreach ([2,3,4] as $count) {
    $items=[];
    for ($i=1;$i<=$count;$i++) { $items[]=['position'=>$i,'url'=>'/media/'.$i.'.jpg','alt'=>$i===1 ? 'Gold sun above blue mountains.' : '']; }
    $doc=new DOMDocument(); @$doc->loadHTML(renderFixture('media',['type'=>'gallery','count'=>$count,'items'=>$items]));
    $links=$doc->getElementsByTagName('a'); $images=$doc->getElementsByTagName('img');
    check($links->length===$count,'Every gallery item has a route');
    for ($i=0;$i<$count;$i++) {
        check(str_contains($links->item($i)->getAttribute('aria-label'),'Open photo '.($i+1).' of '.$count), 'Gallery position is distinguishable');
        check($images->item($i)->getAttribute('alt')===($i===0 ? 'Gold sun above blue mountains.' : ''),'Authored or empty alt is preserved');
    }
    check(str_contains($links->item(0)->getAttribute('aria-label'),'Gold sun'), 'Link name includes authored description');
    check(str_contains($links->item(1)->getAttribute('aria-label'),'No image description provided'), 'Fallback is honest about missing description');
}
check(str_contains(bms_markdown_to_html('![](/media/decorative.jpg)'), 'alt=""'), 'Explicit Markdown decorative alt remains empty');
$html=renderFixture('media',['type'=>'image','url'=>'/media/empty.jpg','alt'=>'']);
check(str_contains($html,'aria-label="Open photo. No image description provided."'), 'Empty-alt linked image still has an accessible action name');
$bms_component_data=['like'=>['enabled'=>true,'count'=>2,'liked'=>false,'label'=>'2 likes','action_label'=>'Like this post.']];
ob_start(); include dirname(__DIR__).'/_bonumark_stream/app/views/default/components/stream-card/actions.php'; $html=ob_get_clean();
check(str_contains($html,'aria-label="Like this post. 2 likes"') && str_contains($html,'class="stream-like-sr-text">Like</span>') && str_contains($html,'class="stream-like-text">2</span>'), 'Visible Like action and full accessible count agree');
echo "PASS public semantics: six landmarks, skip focus, three galleries, authored/empty alt, named image actions, visible Like contract\n";
