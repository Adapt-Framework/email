<?php

namespace adapt\email{
    
    /* Prevent direct access */
    defined('ADAPT_STARTED') or die;
    
    class model_email_account extends \adapt\model{
        
        public function __construct($id = null, $data_source = null){
            parent::__construct('email_account', $id, $data_source);
        }
        
        public function initialise(){
            parent::initialise();
            
            $this->_auto_load_only_tables = array(
                'email_folder'
            );
            
            $this->_auto_load_children = true;
        }
        
        /* Load default account */
        public function load_default(){
            $result = $this->data_source->sql
                ->select('*')
                ->from('email_account')
                ->where(
                    new \adapt\sql_condition(
                        $this->data_source->sql('date_deleted'),
                        'is',
                        $this->data_source->sql('null')
                    )
                )
                ->order_by('priority')
                ->limit(1)
                ->execute()
                ->results();
            
            if (is_array($result) && count($result) == 1){
                return $this->load_by_data($result[0]);
            }
            
            return false;
        }
        
        public function new_email(){
            $email = new model_email();
            $folder = $this->get_drafts();
            $email->add($folder);
            return $email;
        }
        
        public function new_email_from_template($template_name){
            $templates = $this->get_templates();
            if ($templates && $templates instanceof \adapt\model && $templates->table_name == 'email_folder'){
                $result = $this
                    ->data_source
                    ->sql
                    ->select('*')
                    ->from('email')
                    ->where(
                        new \adapt\sql_and(
                            new \adapt\sql_condition(
                                $this->data_source->sql('date_deleted'),
                                'is',
                                $this->data_source->sql('null')
                            ),
                            new \adapt\sql_condition(
                                $this->data_source->sql('email_folder_id'),
                                '=',
                                $templates->email_folder_id
                            ),
                            new \adapt\sql_condition(
                                $this->data_source->sql('name'),
                                '=',
                                $template_name
                            )
                        )
                    )
                    ->execute()
                    ->results();
                
                if (is_array($result) && count($result) == 1){
                    $template = new model_email();
                    if ($template->load_by_data($result)){
                        $email = $template->copy();
                        
                        /* Remove the folder */
                        for($i = 0; $i < $email->count(); $i++){
                            $child = $email->get($i);
                            if ($child && $child instanceof \adapt\model && $child->table_name == 'email_folder'){
                                $email->remove($i);
                            }
                        }
                        
                        /* Add the drafts folder */
                        $folder = $this->get_drafts();
                        $email->add($folder);
                        
                        /* Set the flags */
                        $email->template = 'No';
                        $email->draft = 'Yes';
                        $email->sent = 'No';
                        $email->queued_to_send = 'No';
                        $email->received = 'No';
                        $email->seen = 'No';
                        $email->flagged = 'No';
                        $email->answered = 'No';
                        
                        return $email;
                    }
                }else{
                    $this->error("Unable to find template '{$template_name}' in this account");
                }
            }
            
            return null;
        }
        
        
        /*
         * Override save to create new mail folders
         */
        public function save(){
            if (!$this->is_loaded){
                /* Inbox */
                $folder = new model_email_folder();
                $folder->type = 'Inbox';
                $folder->label = 'Inbox';
                $this->add($folder);
                
                /* Outbox */
                $folder = new model_email_folder();
                $folder->type = 'Outbox';
                $folder->label = 'Outbox';
                $this->add($folder);
                
                /* Drafts */
                $folder = new model_email_folder();
                $folder->type = 'Drafts';
                $folder->label = 'Drafts';
                $this->add($folder);
                
                /* Sent items */
                $folder = new model_email_folder();
                $folder->type = 'Sent items';
                $folder->label = 'Sent items';
                $this->add($folder);
                
                /* Trash */
                $folder = new model_email_folder();
                $folder->type = 'Trash';
                $folder->label = 'Trash';
                $this->add($folder);
                
                /* Templates */
                $folder = new model_email_folder();
                $folder->type = 'Templates';
                $folder->label = 'Templates';
                $this->add($folder);
            }
            
            return parent::save();
        }
        
        /*
         * Override delete to cascade to all folders and emails
         */
        public function delete(){
            //TODO:
        }
        
        /*
         * Fetch email from the imap server
         */
        public function fetch($mailbox = null){
            
            if ($this->is_loaded && !is_null($this->imap_username) && !is_null($this->imap_password) && !is_null($this->imap_hostname)){
                $imap = new imap($this->imap_username, $this->imap_password, $this->imap_hostname, $this->imap_port, $this->imap_options);
                
                if ($imap){
                    
                    /* Lets get the mailbox structure from the imap server */
                    $structure = $imap->list_mailboxes();
                    
                    $children =  $this->get();
                    
                    foreach($structure as $folder){
                        
                        $found = false;
                        foreach($children as $child){
                            if ($child instanceof \adapt\model && $child->table_name == 'email_folder'){
                                if ($child->imap_name == $folder){
                                    $found = true;
                                }
                            }
                        }
                        
                        if (!$found){
                            //print new html_pre($folder);
                            if ($folder == "INBOX"){
                                $inbox = $this->get_inbox();
                                if ($inbox){
                                    $inbox->imap_name = $folder;
                                    $inbox->save();
                                }else{
                                    $inbox = new model_email_folder();
                                    $inbox->type = 'Inbox';
                                    $inbox->label = 'Inbox';
                                    $inbox->imap_name = $folder;
                                    $this->add($inbox);
                                }
                            }elseif(preg_match("/[^\/]*Sent[^\/]*/i", $folder)){
                                $ef = $this->get_sent_items();
                                
                                if ($ef){
                                    if (!is_null($ef->imap_name)){
                                        $ef = new model_email_folder();
                                        $ef->type = 'Folder';
                                        $ef->imap_name = $folder;
                                        $ef->name = $folder;
                                        
                                    }else{
                                        $ef->imap_name = $folder;
                                    }
                                    
                                    $ef->save();
                                }else{
                                    $ef = new model_email_folder();
                                    $ef->type = 'Sent items';
                                    $ef->label = $folder;
                                    $ef->imap_name = $folder;
                                    $this->add($sent);
                                }
                            }elseif(preg_match("/[^\/]*(Trash|Deleted)[^\/]*/i", $folder)){
                                $ef = $this->get_trash();
                                if ($ef){
                                    if (!is_null($ef->imap_name)){
                                        $ef = new model_email_folder();
                                        $ef->type = 'Folder';
                                        $ef->imap_name = $folder;
                                        $ef->name = $folder;
                                    }else{
                                        $ef->imap_name = $folder;
                                    }
                                    
                                    $ef->save();
                                }else{
                                    $ef = new model_email_folder();
                                    $ef->type = 'Trash';
                                    $ef->label = $folder;
                                    $ef->imap_name = $folder;
                                    $this->add($sent);
                                }
                            }elseif(preg_match("/[^\/]*Drafts[^\/]*/i", $folder)){
                                $ef = $this->get_drafts();
                                if ($ef){
                                    if (!is_null($ef->imap_name)){
                                        $ef = new model_email_folder();
                                        $ef->type = 'Folder';
                                        $ef->imap_name = $folder;
                                        $ef->name = $folder;
                                    }else{
                                        $ef->imap_name = $folder;
                                    }
                                    
                                    $ef->save();
                                }else{
                                    $ef = new model_email_folder();
                                    $ef->type = 'Drafts';
                                    $ef->label = $folder;
                                    $ef->imap_name = $folder;
                                    $this->add($sent);
                                }
                            }else{
                                $this->create_folder($folder);
                            }
                        }
                    }
                    
                    /* Lets pull the headers from the server */
                    if ($mailbox){
                        print new html_pre("Fetching: {$mailbox}");
                        
                        $folder = $this->get_folder($mailbox);
                        
                        /* Get the message ID's */
                        $search = null;
                        if (!is_null($folder->date_synced)){
                            $date = new \adapt\date($folder->date_synced);
                            $search = "SINCE " . $date->date('d-M-Y');
                        }
                        
                        $ids = $imap->search($mailbox, $search); //TODO: Folder by last sync date
                        foreach($ids as $id){
                            print $id;
                            $header = $imap->get_header($id);
                            print new html_pre(print_r($header, true));
                            print new html_pre("Message ID: {$header->Msgno}");
                            /* Have me already recieved this message? */
                            $email = new model_email();
                            $email->load_by_imap_message_id(trim($header->Msgno), $folder->email_folder_id);
                            print new html_pre(print_r($email->errors(), true));
                            if ($email->is_loaded){
                                /* Update */
                                //print "{$header['message_id']} Found";
                                print new html_pre("Updating existing record");
                                
                                /* Create new */
                                $date = new \adapt\date();
                                $date->set_date($header->date, "D, d M Y H:i:s O");
                                
                                $email->email_folder_id = $folder->email_folder_id;
                                $email->imap_date = $date->date('Y-m-d H:i:s');
                                $email->imap_message_id = trim($header->Msgno);
                                $email->imap_message_fetched = 'No';
                                $email->template = 'No';
                                $email->draft = is_null($header->Draft) ? "No" : "Yes";
                                $email->sent = $folder->type == 'Sent items' ? 'Yes' : 'No';
                                $email->queued_to_send = 'No';
                                $email->received = 'Yes';
                                $email->seen = is_null($header->Unseen) || trim($header->Unseen) == '' ? 'Yes' : 'No';
                                $email->flagged = is_null($header->Flagged) || trim($header->Flagged) == '' ? 'No' : 'Yes';
                                $email->answered = is_null($header->Answered) || trim($header->Answered) == '' ? 'No' : 'Yes';
                                if (!is_null($header->Deleted) && trim($header->Deleted) != ''){
                                    $email->date_deleted = $this->data_source->sql('now()');
                                }
                                $from = $header->from[0];
                                $email->sender_name = $from->personal;
                                if (!is_null($from->mailbox) && !is_null($from->host)){
                                    $email->sender_email = $from->mailbox . "@" . $from->host;
                                }
                                
                                $email->subject = $header->subject;
                                $email->date_sent = $date->date('Y-m-d H:i:s');
                                
                                $email->save();
                            }else{
                                /* Clear errors */
                                $email->errors(true);
                                
                                /* Create new */
                                $date = new \adapt\date();
                                $date->set_date($header->date, "D, d M Y H:i:s O");
                                
                                $email->email_folder_id = $folder->email_folder_id;
                                $email->imap_date = $date->date('Y-m-d H:i:s');
                                $email->imap_message_id = trim($header->Msgno);
                                $email->imap_message_fetched = 'No';
                                $email->template = 'No';
                                $email->draft = is_null($header->Draft) ? "No" : "Yes";
                                $email->sent = $folder->type == 'Sent items' ? 'Yes' : 'No';
                                $email->queued_to_send = 'No';
                                $email->received = 'Yes';
                                $email->seen = is_null($header->Unseen) || trim($header->Unseen) == '' ? 'Yes' : 'No';
                                $email->flagged = is_null($header->Flagged) || trim($header->Flagged) == '' ? 'No' : 'Yes';
                                $email->answered = is_null($header->Answered) || trim($header->Answered) == '' ? 'No' : 'Yes';
                                if (!is_null($header->Deleted) && trim($header->Deleted) != ''){
                                    $email->date_deleted = $this->data_source->sql('now()');
                                }
                                $from = $header->from[0];
                                $email->sender_name = $from->personal;
                                if (!is_null($from->mailbox) && !is_null($from->host)){
                                    $email->sender_email = $from->mailbox . "@" . $from->host;
                                }
                                
                                $email->subject = $header->subject;
                                $email->date_sent = $date->date('Y-m-d H:i:s');
                                
                                $recipients = $header->to;
                                if (is_array($recipients) && count($recipients)){
                                    foreach($recipients as $to){
                                        $model = new model_email_recipient();
                                        $model->recipient_type = 'to';
                                        $model->recipient_name = trim($to->personal);
                                        $model->recipient_email = trim($to->mailbox) . "@" . trim($to->host);
                                        $email->add($model);
                                    }
                                }
                                
                                $recipients = $header->cc;
                                if (is_array($recipients) && count($recipients)){
                                    foreach($recipients as $cc){
                                        $model = new model_email_recipient();
                                        $model->recipient_type = 'cc';
                                        $model->recipient_name = trim($cc->personal);
                                        $model->recipient_email = trim($cc->mailbox) . "@" . trim($cc->host);
                                        $email->add($model);
                                    }
                                }
                                
                                print new html_pre($email);
                                print new html_pre(print_r($email->errors(), true));
                                
                                $email->save();
                                //print "{$header['message_id']} not found";
                            }
                            
                            
                        }
                        
                        $folder->date_synced = $this->data_source->sql('now()');
                        $folder->save();
                        
                    }else{
                        /* Fetch all */
                        foreach($children as $child){
                            $this->fetch($this->get_imap_path($child));
                        }
                    }
                    
                    
                    $this->date_synced = $this->data_source->sql('now()');
                    $this->save();
                    
                }else{
                    //Handle error here
                }
                
                
                
            }
        }
        
        public function get_imap_path($model_email_folder){
            if ($model_email_folder instanceof \adapt\model && $model_email_folder->table_name == 'email_folder'){
                $name = $model_email_folder->imap_name;
                if (is_null($model_email_folder->parent_email_folder_id)){
                    return $name;
                }else{
                    $model = $this->get_folder_by_id($model_email_folder->parent_email_folder_id);
                    if ($model){
                        $name = $this->get_imap_path($model) . "/" . $name;
                    }
                    
                    return $name;
                }
            }
            
            return "";
        }
        
        public function get_folder_by_id($id){
            $children = $this->get();
            
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->email_folder_id == $id){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_folder($imap_path, $parent_id = null){
            $path = explode("/", $imap_path);
            $path = array_reverse($path);
            $base_folder = array_pop($path);
            $path = array_reverse($path);
            $path = implode("/", $path);
            
            $children = $this->get();
            
            foreach($children as $child){
                if ((is_null($parent_id) && is_null($child->parent_email_folder_id) && $child->imap_name == $base_folder) || ($parent_id == $child->parent_email_folder_id && $child->imap_name == $base_folder)){   
                    if ((is_null($parent_id) && is_null($child->parent_email_folder_id)) || ($child->imap_name == $base_folder)){
                        if ($path != ""){
                            return $this->get_folder($path, $child->email_folder_id);
                        }else{
                            return $child;
                        }
                    }
                }
            }
            
            return null;
        }
        
        public function create_folder($imap_path, $parent_id = null){
            $path = explode("/", $imap_path);
            $path = array_reverse($path);
            $base_folder = array_pop($path);
            $path = array_reverse($path);
            $path = implode("/", $path);
            
            $children = $this->get();
            
            $found = false;
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder'){
                    if ((is_null($parent_id) && is_null($child->parent_email_folder_id) && $child->imap_name == $base_folder) || ($parent_id == $child->parent_email_folder_id && $child->imap_name == $base_folder)){
                        
                        $found = true;
                        if ($path != ""){
                            $this->create_folder($path, $child->email_folder_id);
                        }else{
                            return $child;
                        }
                    }
                }
            }
            
            if (!$found){
                $folder = new model_email_folder();
                $folder->parent_email_folder_id = $parent_id;
                $folder->imap_name = $base_folder;
                $folder->label = $base_folder;
                $folder->type = 'Folder';
                $folder->email_account_id = $this->email_account_id;
                $folder->save();
                $this->add($folder);
                $this->save();
                return $folder;
            }
            
            
            return null;
        }
        
        public function get_inbox(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Inbox'){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_outbox(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Outbox'){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_sent_items(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Sent items'){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_trash(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Trash'){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_drafts(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Drafts'){
                    return $child;
                }
            }
            
            return null;
        }
        
        public function get_templates(){
            $children = $this->get();
            foreach($children as $child){
                if ($child instanceof \adapt\model && $child->table_name == 'email_folder' && $child->type == 'Templates'){
                    return $child;
                }
            }
            
            return null;
        }
        
        
        /*
         * Send an email
         * Use only when you need to send an email immediately,
         * this function *is* blocking.
         */
        public function send($model_email){
            if ($this->save_to_outbox($model_email)){
                
                $email = $model_email->render();
                if ($email && $email != ""){
                    
                    /* Create a new SMTP instance */
                    $smtp = new smtp();
                    
                    /* Do we have relay information? */
                    if ($this->smtp_hostname){
                        $smtp->relay_via($this->smtp_hostname, $this->smtp_port, $this->smtp_security, $this->smtp_username, $this->smtp_password);
                    }
                    
                    $smtp->from($this->sender_email);
                    
                    $children = $model_email->get();
                    
                    foreach($children as $child){
                        if ($child instanceof \adapt\model && $child->table_name == 'email_recipient'){
                            $smtp->to($child->recipient_email);
                        }
                    }
                    
                    $smtp->data($email);
                    
                    $success = $smtp->send();
                    if ($success){
                        
                        return $this->save_to_sent_items($model_email);
                        
                    }else{
                        $errors = $smtp->errors(true);
                        foreach($errors as $error){
                            $this->error($error);
                        }
                    }
                    
                }else{
                    $this->error("Email is empty");
                }
                
            }
            
            return false;
        }
        
        /*
         * Queue the email so it gets sent out
         * in the next scheduled cycle.
         */
        public function queue($model_email){
            if ($this->save_to_outbox($model_email)){
                /* Set the queued_to_send flag */
                $model_email->queued_to_send = 'Yes';
                return $model_email->save();
            }
            
            return false;
        }
        
        /*
         * Helper functions
         */
        public function save_to_sent_items($model_email){
            $output = false;
            if ($this->is_loaded){
                $sent_items = $this->get_sent_items();
                if ($sent_items && $sent_items instanceof \adapt\model && $sent_items->table_name == 'email_folder'){
                    $model_email->email_folder_id = $sent_items->email_folder_id;
                    $model_email->template = 'No';
                    $model_email->draft = 'No';
                    $model_email->sent = 'Yes';
                    $model_email->queued_to_send = 'No';
                    $model_email->received = 'No';
                    $model_email->seen = 'No';
                    $model_email->flagged = 'No';
                    $model_email->answered = 'No';
                    $output = $model_email->save();
                }else{
                    $this->error("Unable to find the sent items folder");
                }
            }else{
                $this->error("Unable to save email to sent items, email account not loaded.");
            }
            
            return $output;
        }
        
        public function save_to_dafts($model_email){
            $output = false;
            if ($this->is_loaded){
                $drafts = $this->get_drafts();
                if ($drafts && $drafts instanceof \adapt\model && $drafts->table_name == 'email_folder'){
                    $model_email->email_folder_id = $drafts->email_folder_id;
                    $model_email->template = 'No';
                    $model_email->draft = 'Yes';
                    $model_email->sent = 'No';
                    $model_email->queued_to_send = 'No';
                    $model_email->received = 'No';
                    $model_email->seen = 'No';
                    $model_email->flagged = 'No';
                    $model_email->answered = 'No';
                    $output = $model_email->save();
                }else{
                    $this->error("Unable to find the drafts folder");
                }
            }else{
                $this->error("Unable to save email to drafts folder, email account not loaded.");
            }
            
            return $output;
        }
        
        public function save_to_outbox($model_email){
            $output = false;
            if ($this->is_loaded){
                $outbox = $this->get_outbox();
                if ($outbox && $outbox instanceof \adapt\model && $outbox->table_name == 'email_folder'){
                    $model_email->email_folder_id = $outbox->email_folder_id;
                    $model_email->template = 'No';
                    $model_email->draft = 'No';
                    $model_email->sent = 'No';
                    $model_email->queued_to_send = 'No';
                    $model_email->received = 'No';
                    $model_email->seen = 'No';
                    $model_email->flagged = 'No';
                    $model_email->answered = 'No';
                    $output = $model_email->save();
                }else{
                    $this->error("Unable to find the outbox folder");
                }
            }else{
                $this->error("Unable to save email to the outbox, email account not loaded.");
            }
            
            return $output;
        }
        
        public function save_to_templates($model_email){
            $output = false;
            if ($this->is_loaded){
                $templates = $this->get_templates();
                if ($templates && $templates instanceof \adapt\model && $templates->table_name == 'email_folder'){
                    $model_email->email_folder_id = $templates->email_folder_id;
                    $model_email->template = 'Yes';
                    $model_email->draft = 'No';
                    $model_email->sent = 'No';
                    $model_email->queued_to_send = 'No';
                    $model_email->received = 'No';
                    $model_email->seen = 'No';
                    $model_email->flagged = 'No';
                    $model_email->answered = 'No';
                    if (is_null($model_email->sender_email)){
                        $model_email->sender_email = $this->account_email;
                    }
                    $output = $model_email->save();
                }else{
                    $this->error("Unable to find the template folder");
                }
            }else{
                $this->error("Unable to save email to the template, email account not loaded.");
            }
            
            return $output;
        }
        
    }
    
}

?>