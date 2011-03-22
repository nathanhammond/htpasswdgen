<?php
class valid {
	function user(&$value) {
		return !empty($value) && strpos($value, ' ') === false;
	}
	function pass(&$value) {
		return !empty($value);
	}
	function group(&$value) {
		return !empty($value) && strpos($value, ' ') === false;
	}
}

class Users {
	private $path;
	private $users;
	public $error = false;

	public function __construct($path = '.htpasswd') {
		$this->path = $path;
		if (!file_exists($this->path)) {
			touch($this->path);
		}
		$this->readfile();
	}

	private function readfile() {
		$this->users = array_map(array($this, 'process'), file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
	}

	private function process($value) {
		return array_shift(explode(':', $value));
	}

	public function add(&$user, &$pass) {
		if (!valid::user($user)) { $this->error = 'This is not a valid username.'; return false; }
		if (!valid::pass($pass)) { $this->error = 'This is not a valid password.'; return false; }
		if ($this->exists($user)) { $this->error = 'This user already exists.'; return false; }
		
		$pass = crypt($pass, base64_encode($pass));	
		file_put_contents($this->path, $user.":".$pass."\r\n", FILE_APPEND | LOCK_EX);
		$this->readfile();

		return true;
	}

	public function edit(&$user, &$pass) {
		if (!valid::user($user)) { $this->error = 'This user does not already exist.'; return false; }
		if (!$this->exists($user)) { $this->error = 'This user does not already exist.'; return false; }
		if (!valid::pass($pass)) { $this->error = 'This is not a valid password.'; return false; }
		
		return $this->delete($user) && $this->add($user, $pass);
	}
	
	public function delete($user) {
		if (!valid::user($user)) { $this->error = 'This user does not already exist.'; return false; }
		if (!$this->exists($user)) { $this->error = 'This user does not already exist.'; return false; }

		file_put_contents($this->path, preg_replace('/'.$user.':[^\r\n]*[\r\n]*/m', '', file_get_contents($this->path)));
		$this->readfile();

		return true;
	}
	
	public function get() {
		return $this->users;
	}
		
	private function exists($user) {
		return in_array($user, $this->users);		
	}
}

// A group name appears first on a line, followed by a colon, and then a list of the members of the group, separated by spaces.
class Groups {
	private $path;
	private $groups;
	public $error = false;

	public function __construct($path = '.htgroups') {
		$this->path = $path;
		if (!file_exists($this->path)) {
			touch($this->path);
		}
		$this->readfile();
	}

	private function readfile() {
		$this->groups = array();
		array_map(array($this, 'process'), file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
	}
	
	private function process($value) {
		$segments = explode(':', $value);
		$group = array_shift($segments);
		$users = explode(' ', trim(array_shift($segments)));
		$this->groups[$group] = $users;
	}
	
	public function add(&$name, &$users) {
		if (!valid::group($name)) { $this->error = 'This group name is not valid.'; return false; }
		if ($this->exists($name)) { $this->error = 'This group already exists.'; return false; }
		
		if (is_array($users)) {
			file_put_contents($this->path, $name.': '.implode(' ', $users)."\r\n", FILE_APPEND | LOCK_EX);
		} else {
			file_put_contents($this->path, $name.': '."\r\n", FILE_APPEND | LOCK_EX);
		}
		$this->readfile();
		
		return true;
	}

	public function edit(&$name, &$users) {
		if (!valid::group($name)) { $this->error = 'This group does not already exist.'; return false; }
		if (!$this->exists($name)) { $this->error = 'This group does not already exist.'; return false; }
		
		$this->delete($name);
		$this->add($name, $users);

		return true;
	}

	public function delete(&$name) {
		if (!valid::group($name)) { $this->error = 'This group does not already exist.'; return false; }
		if (!$this->exists($name)) { $this->error = 'This group does not already exist.'; return false; }

		file_put_contents($this->path, preg_replace('/'.$name.':[^\r\n]*[\r\n]*/m', '', file_get_contents($this->path)));
		$this->readfile();

		return true;
	}

	public function deleteuser($user) {
		file_put_contents($this->path, str_replace(' '.$user, '', file_get_contents($this->path)));
		$this->readfile();

		return true;
	}
	
	public function get() {
		return $this->groups;
	}

	private function exists($name) {
		return in_array($name, array_keys($this->groups));
	}
}

/*
	AuthType Basic
	AuthName "Password Required"
	AuthUserFile /www/passwords/password.file
	AuthGroupFile /www/passwords/group.file
	Require Group admins
*/
class Access {
	public $users;
	public $groups;
	
	function __construct() {
		// Run on install.
		
		// Run all subsequent times.
		require_once("config.php");
		$this->users = new Users($config['paths']['htpasswd']);
		$this->groups = new Groups($config['paths']['htgroups']);
	}
}

$access = new Access();

/* Process Actions */
if ($_POST) {
	switch($_GET["a"]) {
		case 'user_add':
			$access->users->add($_POST['user_name'], $_POST['user_pass']);
		break;
		case 'user_edit':
			$access->users->edit($_POST['user_edit'], $_POST['user_edit_password']);
		break;
		case 'user_delete':
			if ($access->users->delete($_POST['user_delete'])) {
				$access->groups->deleteuser($_POST['user_delete']);
			}
		break;
		case 'group_add':
			$access->groups->add($_POST['group_name'], $_POST['group_add_users']);
		break;
		case 'group_edit':
			$access->groups->edit($_POST['group_edit'], $_POST['group_edit_users']);
		break;
		case 'group_delete':
			$access->groups->delete($_POST['group_delete']);
		break;
	}
}

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title>User Access Manager</title>
	<style type="text/css">
		html, body, div, span, object, iframe, h1, h2, h3, h4, h5, h6, p, blockquote, pre, a, abbr, acronym, address, code, del, dfn, em, img, q, dl, dt, dd, ol, ul, li, fieldset, form, label, legend, table, caption, tbody, tfoot, thead, tr, th, td {margin:0;padding:0;border:0;font-weight:inherit;font-style:inherit;font-size:100%;font-family:inherit;vertical-align:baseline;}
		body {line-height:1.5;}
		table {border-collapse:separate;border-spacing:0;}
		caption, th, td {text-align:left;font-weight:normal;}
		table, td, th {vertical-align:middle;}
		blockquote:before, blockquote:after, q:before, q:after {content:"";}
		blockquote, q {quotes:"" "";}
		a img {border:none;}
		
		h1, h2, h3 {font-weight: bold;}
		h1 { font-size: 2em; margin: .5em 0; }
		h2 { font-size: 1.6em; margin: .5em 0; }
		h3 { font-size: 1.3em; margin: .5em 0; }
		
		thead * { font-weight: bold; }
	</style>
	<script type="text/javascript">
		function markinputs() {
			return;
			var inputs = document.getElementsByTagName('input');
			for (var i = 0; i < inputs.length; i++) {
				inputs[i].parentNode.insertBefore(document.createTextNode(inputs[i].name), inputs[i].nextSibling)
			}
		}
	</script>
</head>
<body onload="markinputs();">
<h1>User Access Manager</h1>
<?php if ($access->users->error) { echo '<div class="error">'.$access->users->error.'</div>'; } ?>
<?php if ($access->groups->error) { echo '<div class="error">'.$access->groups->error.'</div>'; } ?>

<h2>Users</h2>
<?php if (is_array($access->users->get()) && count($access->users->get())) { ?>
<table>
	<thead><tr><td>Username</td><td>Password</td><td>Delete</td></tr></thead>
	<tbody>
		<?php foreach ($access->users->get() as $user) { ?>
			<tr><td><?php echo $user; ?></td>
				<td><form action="?a=user_edit" method="post"><div><input type="hidden" name="user_edit" value="<?php echo $user; ?>" /><input type="text" class="text" name="user_edit_password" /><input type="submit" value="Update" /></div></form></td>
				<td><form action="?a=user_delete" method="post"><div><input type="hidden" name="user_delete" value="<?php echo $user; ?>" /><input type="submit" value="Delete" /></div></form></td></tr>
		<?php } ?>
	</tbody>
</table>
<?php } else { ?>
	<p>No Users.</p>
<?php } ?>

<h3>Add User</h3>
<form action="?a=user_add" method="post"><div>
	<label for="user_name">Username</label>
		<input type="text" class="text" name="user_name" id="user_name" />
	<label for="user_pass">Password</label>
		<input type="text" class="text" name="user_pass" id="user_pass" />
	<input type="submit" value="Submit" />
</div></form>

<h2>Groups</h2>
<?php if (is_array($access->groups->get()) && count($access->groups->get())) { ?>
<table>
	<thead><tr><td>Group</td><td>Users</td><td>Delete</td></tr></thead>
	<tbody>
		<?php foreach ($access->groups->get() as $group => $users) { ?>
			<tr><td><?php echo $group; ?></td>
				<td><?php if (count($access->users->get()) > 0) { ?><form action="?a=group_edit" method="post"><div><input type="hidden" name="group_edit" value="<?php echo $group; ?>" /><?php foreach ($access->users->get() as $user) { ?><input type="checkbox" class="checkbox" name="group_edit_users[]" id="<?php echo $group; ?>_user_<?php echo $user; ?>"<?php if (in_array($user, $users)) { echo ' checked="checked"'; } ?> value="<?php echo $user; ?>" /> <label for="<?php echo $group; ?>_user_<?php echo $user; ?>"><?php echo $user; ?></label><?php } ?><input type="submit" value="Update" /></div></form><?php } else { ?><p>No Users.</p><?php } ?></td>
				<td><form action="?a=group_delete" method="post"><div><input type="hidden" name="group_delete" value="<?php echo $group; ?>" /><input type="submit" value="Delete" /></div></form></td></tr>
		<?php } ?>
	</tbody>
</table>
<?php } else { ?>
	<p>No Groups.</p>
<?php } ?>

<h3>Add Group</h3>
<form action="?a=group_add" method="post"><div>
	<label for="group_name">Name</label>
		<input type="text" class="text" name="group_name" id="group_name" />
	<?php foreach ($access->users->get() as $value) { ?>
	<input type="checkbox" class="checkbox" name="group_add_users[]" id="user_<?php echo $value; ?>" value="<?php echo $value; ?>" /> <label for="user_<?php echo $value; ?>"><?php echo $value; ?></label>
	<?php } ?>
	<input type="submit" value="Submit" />
</div></form>
</body>
</html>