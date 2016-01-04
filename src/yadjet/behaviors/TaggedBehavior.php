<?php

namespace yadjet\behaviors;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\Inflector;
use common\models\Yad;

/**
 * Tagged Behavior class
 * @author hiscaler<hiscaler@gmail.com>
 */
class TaggedBehavior extends \yii\base\Behavior
{

    private $_oldTags;

    /**
     * 表标签字段
     * @var string
     */
    public $attribute = 'tags';

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterFind()
    {
        $this->_oldTags = $this->owner->{$this->attribute};
    }

    public function afterSave()
    {
        $owner = $this->owner;
        $this->updateFrequency($owner->id, $this->_oldTags, $owner->{$this->attribute});
    }

    public function afterDelete()
    {
        $owner = $this->owner;
        $this->updateFrequency($owner->id, $this->_oldTags, '');
    }

    public static function string2array($tags)
    {
        return preg_split('/\s*,\s*/', trim($tags), -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function array2string($tags)
    {
        return implode(', ', $tags);
    }

    public function updateFrequency($entityId, $oldTags, $newTags)
    {
        $oldTags = self::string2array($oldTags);
        $newTags = self::string2array($newTags);
        $this->addTags($entityId, array_values(array_diff($newTags, $oldTags)));
        $this->removeTags($entityId, array_values(array_diff($oldTags, $newTags)));
    }

    /**
     * 标签添加
     * @param integer $entityId 提交数据的主键
     * @param array $tags 要添加的 tags 集合
     */
    public function addTags($entityId, $tags)
    {
        $tenantId = Yad::getTenantId();
        $owner = $this->owner;
        $entityName = $owner::className2Id();
        $now = time();
        $userId = Yii::$app->user->id;
        $updateFrequencyTags = [];
        $batchInsertEntityTags = [];
        $db = Yii::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            foreach ($tags as $name) {
                $tagId = $db->createCommand("SELECT [[id]] FROM {{%tag}} WHERE [[name]] = :name AND [[tenant_id]] = :tenantId")->bindValues([':name' => $name, ':tenantId' => $tenantId])->queryScalar();
                if ($tagId) {
                    $batchInsertEntityTags[] = [$entityId, $entityName, $tagId];
                    $updateFrequencyTags[] = $tagId;
                } else {
                    // Add new tag
                    $newTag = $db->createCommand()->insert('{{%tag}}', [
                            'alias' => Inflector::slug($name),
                            'name' => $name,
                            'frequency' => 1,
                            'tenant_id' => $tenantId,
                            'created_by' => $userId,
                            'created_at' => $now,
                            'updated_by' => $userId,
                            'updated_at' => $now
                        ])->execute();
                    if ($newTag) {
                        $batchInsertEntityTags[] = [$entityId, $entityName, $db->getLastInsertID()];
                    }
                }
            }
            if ($updateFrequencyTags) {
                $db->createCommand('UPDATE {{%tag}} SET [[frequency]] = [[frequency]] + 1 WHERE [[id]] IN (' . implode(', ', $updateFrequencyTags) . ')')->execute();
            }
            if ($batchInsertEntityTags) {
                $db->createCommand()->batchInsert('{{%entity_tag}}', ['entity_id', 'entity_name', 'tag_id'], $batchInsertEntityTags)->execute();
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            $owner->{$this->attribute} = null;
        }
    }

    /**
     * 标签清理
     * @param type $entityId 删除数据的主键
     * @param type $tags 要添加的 tags 集合
     */
    public function removeTags($entityId, $tags)
    {
        if (empty($tags)) {
            return;
        }

        $owner = $this->owner;
        $db = Yii::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            $tagIds = (new Query)
                ->select(['id'])
                ->from(['{{%tag}}'])
                ->where([
                    'name' => $tags,
                    'tenant_id' => Yad::getTenantId()
                ])
                ->column();
            if ($tagIds) {
                $db->createCommand('UPDATE {{%tag}} SET [[frequency]] = [[frequency]] - 1 WHERE [[id]] IN (' . implode(', ', $tagIds) . ')')->execute();
            }
            // Delete tags
            $db->createCommand()->delete('{{%tag}}', '[[frequency]] <= 0')->execute();
            // Delete entity tags
            $condition = '[[entity_id]] = :entityId AND [[entity_name]] = :entityName';
            if ($tagIds) {
                $condition .= ' AND [[tag_id]] IN (' . implode(', ', $tagIds) . ')';
            }
            $db->createCommand()->delete('{{%entity_tag}}', $condition, [':entityId' => $entityId, ':entityName' => $owner::className2Id()])->execute();
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
        }
    }

}
