<?php namespace Todaymade\Daux\Format\Confluence;

use Todaymade\Daux\Daux;
use Todaymade\Daux\Tree\Content;
use Todaymade\Daux\Tree\Directory;
use Todaymade\Daux\Tree\Entry;

class Generator
{
    /**
     * @var string
     */
    protected $prefix;

    public function generate(Daux $daux)
    {
        $confluence = $daux->getParams()['confluence'];

        $this->prefix = trim($confluence['prefix']) . " ";

        $params = $daux->getParams();

        echo "Generating Tree...\n";
        $tree = $this->generateRecursive($daux->tree, $params);
        $tree['title'] = $this->prefix . $daux->getParams()['title'];

        echo "Start Publishing...\n";
        $publisher = new Publisher($confluence);
        $publisher->publish($tree);

        echo "Done !\n";
    }

    private function generateRecursive(Entry $tree, array $params, $base_url = '')
    {
        $final = ['title' => $this->prefix . $tree->getTitle()];
        $params['base_url'] = $params['base_page'] = $base_url;

        $params['image'] = str_replace('<base_url>', $base_url, $params['image']);
        if ($base_url !== '') {
            $params['entry_page'] = $tree->getFirstPage();
        }
        foreach ($tree->value as $key => $node) {
            if ($node instanceof Directory) {
                $final['children'][$this->prefix . $node->getTitle()] = $this->generateRecursive(
                    $node,
                    $params,
                    '../' . $base_url
                );
            } elseif ($node instanceof Content) {
                $params['request'] = $node->getUrl();
                $params['file_uri'] = $node->getName();

                $data = [
                    'title' => $this->prefix . $node->getTitle(),
                    'file' => $node,
                    'page' => MarkdownPage::fromFile($node, $params),
                ];

                // As the page is lazily generated
                // We do it now to fail fast in case of problem
                $data['page']->getContent();

                if ($key == 'index.html') {
                    $final['title'] = $this->prefix . $tree->getTitle();
                    $final['file'] = $node;
                    $final['page'] = $data['page'];
                } else {
                    $final['children'][$data['title']] = $data;
                }
            }
        }

        return $final;
    }
}
