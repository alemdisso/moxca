<?php

class Moxca_Blog_TaxonomyMapper extends Moxca_Taxonomy_TaxonomyMapper
{

    protected $db;
    protected $identityMap;

    function __construct()
    {
        $this->db = Zend_Registry::get('db');
        $this->identityMap = new SplObjectStorage;
    }

    public function getAllIds()
    {
        $query = $this->db->prepare('SELECT id FROM moxca_blog_categories WHERE 1=1;');
        $query->execute();
        $resultPDO = $query->fetchAll();

        $result = array();
        foreach ($resultPDO as $row) {
            $result[] = $row['id'];
        }
        return $result;

    }

    public function insertCategory($termId)
    {

        $query = $this->db->prepare("INSERT INTO moxca_terms_taxonomy (term_id, taxonomy, count)
            VALUES (:termId, 'category', 0)");

        $query->bindValue(':termId', $termId, PDO::PARAM_INT);

        $query->execute();

    }

    public function insertPostCategoryRelationship(Moxca_Blog_Post $obj)
    {

        $termTaxonomy = $this->existsCategory($obj->getCategory());
        if (!$termTaxonomy) {
            $termTaxonomy = $this->insertCategory($obj->getCategory());
        }
        $query = $this->db->prepare("INSERT INTO moxca_terms_relationships (object, term_taxonomy)
            VALUES (:postId, :termTaxonomy)");

        $query->bindValue(':postId', $obj->getId(), PDO::PARAM_STR);
        $query->bindValue(':termTaxonomy', $termTaxonomy, PDO::PARAM_STR);

        $query->execute();


    }

    public function insertPostKeyword($termId)
    {

        $query = $this->db->prepare("INSERT INTO moxca_terms_taxonomy (term_id, taxonomy, count)
            VALUES (:termId, 'post_keyword', 0)");

        $query->bindValue(':termId', $termId, PDO::PARAM_INT);

        $query->execute();

        return (int)$this->db->lastInsertId();


    }



    private function createCategoryIfNeeded($termId)
    {
        $existsCategoryWithTerm = $this->existsCategory($termId);
        if (!$existsCategoryWithTerm) {
            $existsCategoryWithTerm = $this->insertCategory($termId);
        }

        return $existsCategoryWithTerm;

    }

    private function createPostKeywordIfNeeded($termId)
    {
        $existsKeywordWithTerm = $this->existsPostKeyword($termId);
        if (!$existsKeywordWithTerm) {
            $existsKeywordWithTerm = $this->insertPostKeyword($termId);
        }

        return $existsKeywordWithTerm;

    }


    public function existsCategory($termId)
    {
        $query = $this->db->prepare("SELECT id FROM moxca_terms_taxonomy WHERE term_id = :termId AND taxonomy = 'category';");

        $query->bindValue(':termId', $termId, PDO::PARAM_INT);
        $query->execute();

        $result = $query->fetch();
        if (!empty($result)) {
            $id = current($result);
            return $id;
        } else {
            return false;
        }
    }

    public function existsPostKeyword($termId)
    {
        $query = $this->db->prepare("SELECT id FROM moxca_terms_taxonomy WHERE term_id = :termId AND taxonomy = 'post_keyword';");

        $query->bindValue(':termId', $termId, PDO::PARAM_INT);
        $query->execute();

        $result = $query->fetch();

        if (!empty($result)) {
            return $result['id'];
        } else {
            return false;
        }
    }

    public function update(Moxca_Blog_Taxonomy $obj)
    {
        if (!isset($this->identityMap[$obj])) {
            throw new Moxca_Blog_TaxonomyMapperException('Object has no ID, cannot update.');
        }

        $query = $this->db->prepare("UPDATE moxca_blog_categories SET label = :label WHERE id = :id;");

        $query->bindValue(':label', $obj->getLabel(), PDO::PARAM_STR);

        try {
            $query->execute();
        } catch (Exception $e) {
            throw new Moxca_Blog_TaxonomyException("sql failed");
        }

    }

    public function findById($id)
    {
        $this->identityMap->rewind();
        while ($this->identityMap->valid()) {
            if ($this->identityMap->getInfo() == $id) {
                return $this->identityMap->current();
            }
            $this->identityMap->next();
        }

        $query = $this->db->prepare('SELECT label FROM moxca_blog_categories WHERE id = :id;');
        $query->bindValue(':id', $id, PDO::PARAM_STR);
        $query->execute();

        $result = $query->fetch();

        if (empty($result)) {
            throw new Moxca_Blog_TaxonomyMapperException(sprintf('There is no taxonomy with id #%d.', $id));
        }
        $uri = $result['uri'];

        $obj = new Moxca_Blog_Taxonomy();
        $this->setAttributeValue($obj, $id, 'id');
        $this->setAttributeValue($obj, $result['label'], 'label');

        $this->identityMap[$obj] = $id;

        return $obj;

    }

    public function delete(Moxca_Blog_Taxonomy $obj)
    {
        if (!isset($this->identityMap[$obj])) {
            throw new Moxca_Blog_TaxonomyMapperException('Object has no ID, cannot delete.');
        }
        $query = $this->db->prepare('DELETE FROM moxca_blog_categories WHERE id = :id;');
        $query->bindValue(':id', $this->identityMap[$obj], PDO::PARAM_INT);
        $query->execute();
        unset($this->identityMap[$obj]);
    }

    public function getAllCategoriesAlphabeticallyOrdered()
    {
        $query = $this->db->prepare('SELECT t.id, t.term
                FROM moxca_terms t
                LEFT JOIN moxca_terms_taxonomy tx ON t.id = tx.term_id
                WHERE tx.taxonomy =  \'category\' ORDER BY t.term');
        $query->execute();
        $resultPDO = $query->fetchAll();
        $data = array();
        foreach ($resultPDO as $row) {
            $data[$row['id']] = $row['term'];
        }
        return $data;

    }


    private function insertListOfKeywords($postId, $keywordsArray)
    {
        foreach($keywordsArray as $k => $termId) {
            $termTaxonomyId = $this->createPostKeywordIfNeeded($termId);
            $this->insertRelationship($postId, $termTaxonomyId);
        }
    }

    public function getTermAndUri($id)
    {
        $query = $this->db->prepare('SELECT t.term, t.uri
                FROM moxca_terms t
                WHERE t.id =  :id ORDER BY t.term');
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->execute();
        $resultPDO = $query->fetchAll();
        $data = current($resultPDO);
        return $data;

    }



    private function setAttributeValue(Moxca_Blog_Taxonomy $a, $fieldValue, $attributeName)
    {
        $attribute = new ReflectionProperty($a, $attributeName);
        $attribute->setAccessible(TRUE);
        $attribute->setValue($a, $fieldValue);
    }

//    public function findTermAndInsertIfNew($term)
//    {
//        $query = $this->db->prepare('SELECT t.id
//                FROM moxca_terms t
//                WHERE t.term = :term');
//        $query->bindValue(':term', $term, PDO::PARAM_STR);
//        $query->execute();
//
//        $result = $query->fetch();
//        if (empty($result)) {
//            $termId = $this->insertTerm($term);
//        } else {
//            $termId = $result['id'];
//        }
//        return $termId;
//
//    }

    public function getPublishedPostsByCategory($category)
    {

        $query = $this->db->prepare('SELECT p.id
                FROM moxca_blog_posts p
                LEFT JOIN moxca_terms_relationships tr ON p.id = tr.object
                LEFT JOIN moxca_terms_taxonomy tx ON tr.term_taxonomy = tx.id
                LEFT JOIN moxca_terms tt ON tx.term_id = tt.id
                WHERE tt.uri = :category
                AND p.status = :published
                AND tx.taxonomy =  \'category\' ORDER BY publication_date DESC;');

        $query->bindValue(':category', $category, PDO::PARAM_STR);
        $query->bindValue(':published', Moxca_Blog_PostStatusConstants::STATUS_PUBLISHED, PDO::PARAM_INT);
        $query->execute();
        $resultPDO = $query->fetchAll();
        $data = array();
        foreach ($resultPDO as $row) {
            $data[] = $row['id'];
        }

        return $data;

    }

    public function insertTerm($term)
    {

        $converter = new Moxca_Util_StringToAscii;
        $uri = $converter->toAscii($term);
        $query = $this->db->prepare("INSERT INTO moxca_terms (term, uri)
            VALUES (:term, :uri)");

        $query->bindValue(':term', $term, PDO::PARAM_STR);
        $query->bindValue(':uri', $uri, PDO::PARAM_STR);

        $query->execute();

        return (int)$this->db->lastInsertId();


    }

    public function postHasKeywords($postId)
    {
        $query = $this->db->prepare('SELECT tx.id, tx.term_id
                FROM moxca_terms_relationships tr
                LEFT JOIN moxca_terms_taxonomy tx ON tr.term_taxonomy = tx.id
                WHERE tr.object = :postId
                AND tx.taxonomy =  \'post_keyword\'');

        $query->bindValue(':postId', $postId, PDO::PARAM_INT);
        $query->execute();
        $resultPDO = $query->fetchAll();

        $data = array();
        foreach ($resultPDO as $row) {
            $data[$row['id']] = $row['term_id'];
        }
        return $data;
    }

    public function postHasCategory($postId)
    {
        $query = $this->db->prepare('SELECT tx.term_id
                FROM moxca_terms_relationships tr
                LEFT JOIN moxca_terms_taxonomy tx ON tr.term_taxonomy = tx.id
                WHERE tr.object = :postId
                AND tx.taxonomy =  \'category\'');

        $query->bindValue(':postId', $postId, PDO::PARAM_INT);
        $query->execute();

        $result = $query->fetch();

        if (!empty($result)) {
            return $result['term_id'];
        } else {
            return false;
        }
    }

    public function updatePostKeywordsRelationships(Moxca_Blog_Post $obj)
    {
        $newKeywords = $obj->getKeywords();
        $postId = $obj->getId();
        $formerKeywords = $this->postHasKeywords($postId);

        if ((is_array($newKeywords)) && (count($newKeywords))) {

            if (!$formerKeywords) {
                // tudo novo, é só incluir
                if (count($newKeywords) > 0) {
                    $this->insertListOfKeywords($postId, $newKeywords);
                }
            } else {
                //descobre se caiu algum
                //   e remove
                $toRemove = array_diff($formerKeywords, $newKeywords);
                if ((is_array($toRemove)) && (count($toRemove))) {
                    foreach($toRemove as $k => $termId) {
                        if ($taxonomyId = $this->createPostKeywordIfNeeded($termId)) {
                            $this->deleteRelationship($postId, $termId, 'post_keyword');
                            $this->decreaseTermTaxonomyCount($taxonomyId, 1);
                        }
                    }
                }

                //descobre quais são novos
                //    e inclui
                $toInclude = array_diff($newKeywords, $formerKeywords);
                if ((is_array($toInclude)) && (count($toInclude))) {
                    $this->insertListOfKeywords($postId, $toInclude);
                }

                if ($newKeywords != $formerKeywords) {
                    $formerTermTaxonomy = $this->createPostKeywordIfNeeded($formerKeywords);
                    $newTermTaxonomy = $this->createPostKeywordIfNeeded($newKeywords);

                    $query = $this->db->prepare("UPDATE moxca_terms_relationships SET term_taxonomy = :newKeyword"
                            . " WHERE object = :postId AND term_taxonomy = :formerKeyword;");

                    $query->bindValue(':postId', $postId, PDO::PARAM_STR);
                    $query->bindValue(':newKeyword', $newTermTaxonomy, PDO::PARAM_STR);
                    $query->bindValue(':formerKeyword', $formerTermTaxonomy, PDO::PARAM_STR);
                    $query->execute();

                    $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = count + 1
                        WHERE id = :termTaxonomy;");
                    $query->bindValue(':termTaxonomy', $newTermTaxonomy, PDO::PARAM_STR);
                    $query->execute();

                    $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = count - 1
                        WHERE id = :termTaxonomy;");
                    $query->bindValue(':termTaxonomy', $formerTermTaxonomy, PDO::PARAM_STR);

                    try {
                        $query->execute();
                    } catch (Exception $e) {
                        $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = 0
                            WHERE id = :termTaxonomy;");
                        $query->bindValue(':termTaxonomy', $formerTermTaxonomy, PDO::PARAM_STR);
                    }
                }
            }
        } else {
            //remove todos
        }

    }

    public function updatePostCategoryRelationship(Moxca_Blog_Post $obj)
    {
        $newCategoryTermId = $obj->getCategory();
        $postId = $obj->getId();
        $formerCategoryTermId = $this->postHasCategory($postId);

        if (!$formerCategoryTermId) {
            if ($newCategoryTermId > 0) {
                $this->insertRelationship($postId, $newCategoryTermId);
            }
        } else {
            if ($newCategoryTermId != $formerCategoryTermId) {
                $formerTermTaxonomy = $this->existsCategory($formerCategoryTermId);
                $newTermTaxonomy = $this->createCategoryIfNeeded($newCategoryTermId);

                $query = $this->db->prepare("UPDATE moxca_terms_relationships SET term_taxonomy = :newCategory"
                        . " WHERE object = :postId AND term_taxonomy = :formerCategory;");

                $query->bindValue(':postId', $postId, PDO::PARAM_STR);
                $query->bindValue(':newCategory', $newTermTaxonomy, PDO::PARAM_STR);
                $query->bindValue(':formerCategory', $formerTermTaxonomy, PDO::PARAM_STR);
                $query->execute();


                $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = count + 1
                    WHERE id = :termTaxonomy;");
                $query->bindValue(':termTaxonomy', $newTermTaxonomy, PDO::PARAM_STR);
                $query->execute();

                $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = count - 1
                    WHERE id = :termTaxonomy;");
                $query->bindValue(':termTaxonomy', $formerTermTaxonomy, PDO::PARAM_STR);

                try {
                    $query->execute();
                } catch (Exception $e) {
                    $query = $this->db->prepare("UPDATE moxca_terms_taxonomy SET count = 0
                        WHERE id = :termTaxonomy;");
                    $query->bindValue(':termTaxonomy', $formerTermTaxonomy, PDO::PARAM_STR);
                }
            }
        }

    }


    public function getAllPostsKeywordsAlphabeticallyOrdered()
    {
        $query = $this->db->prepare('SELECT t.id, t.term, t.uri, tx.count
                FROM moxca_terms t
                LEFT JOIN moxca_terms_taxonomy tx ON t.id = tx.term_id
                WHERE tx.taxonomy =  \'post_keyword\' ORDER BY t.term');
        $query->execute();
        $resultPDO = $query->fetchAll();
        $data = array();
        foreach ($resultPDO as $row) {
            $data[$row['id']] = array('term' => $row['term'], 'uri' => $row['uri'], 'count' => $row['count']);
        }
        return $data;

    }




    public function getKeywordsRelatedToPost($post)
    {

        $query = $this->db->prepare("SELECT t.id, t.term, t.uri
                FROM moxca_terms t
                LEFT JOIN moxca_terms_taxonomy tx ON t.id = tx.term_id
                LEFT JOIN moxca_terms_relationships tr ON tx.id = tr.term_taxonomy
                WHERE tx.taxonomy ='post_keyword'
                AND tr.object = :object ORDER BY t.term");
        $query->bindValue(':object', $post, PDO::PARAM_INT);
        $query->execute();
        $resultPDO = $query->fetchAll();
        $data = array();
        foreach ($resultPDO as $row) {
            $data[$row['uri']] = $row['term'];
        }
        return $data;


    }




}