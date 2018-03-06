<?php if (!defined('APPLICATION')) exit(); ?>
<?php
?><h1><?php echo T($this->Data['Title']); ?></h1>
<div class="Info">
    <?php echo T($this->Data['Description']); ?>
</div>
<form enctype="multipart/form-data" method="POST" action="<?=url("/plugin/pluginfixer");?>">
<input type="file" name="plugin_to_fix"></br>
<input type="submit" value="<?=t("Fix plugin!");?>!"></form>