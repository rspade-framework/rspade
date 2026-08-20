<?php

namespace App\Exceptions;

use Exception;

class MassAssignmentException extends Exception
{
    protected $model_class;
    protected $attempted_fields;
    protected $method_name;
    
    /**
     * Create a new mass assignment exception
     * 
     * @param string $model_class The model class that was targeted
     * @param array $attempted_fields The fields that were attempted to be mass assigned
     * @param bool $was_force Whether forceFill was attempted
     * @param bool $is_static Whether a static method was called
     * @param string|null $method_name Specific method name if relevant
     */
    public function __construct($model_class, array $attempted_fields = [], $was_force = false, $is_static = false, $method_name = null)
    {
        $this->model_class = $model_class;
        $this->attempted_fields = $attempted_fields;
        $this->method_name = $method_name ?: ($was_force ? 'forceFill' : 'fill');
        
        $message = $this->build_message($is_static);
        
        parent::__construct($message);
    }
    
    /**
     * Build the exception message with helpful guidance
     * 
     * @param bool $is_static
     * @return string
     */
    protected function build_message($is_static)
    {
        $short_class = class_basename($this->model_class);
        $var_name = '$' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short_class));
        $fields_list = implode(', ', $this->attempted_fields);
        
        $message = "🚫 MASS ASSIGNMENT PROHIBITED IN RSPADE FRAMEWORK\n\n";
        $message .= "Model: {$this->model_class}\n";
        $message .= "Method: {$this->method_name}()\n";
        
        if (!empty($this->attempted_fields)) {
            $message .= "Attempted fields: {$fields_list}\n";
        }
        
        $message .= "\n";
        $message .= "The RSpade framework prohibits mass assignment for security and code clarity.\n";
        $message .= "All model fields must be explicitly assigned one by one.\n\n";
        
        $message .= "❌ INCORRECT (mass assignment):\n";
        
        if ($is_static) {
            if ($this->method_name === 'create') {
                $message .= "{$short_class}::create(\$request->all());\n";
                $message .= "{$short_class}::create(\$request->validated());\n";
            } elseif ($this->method_name === 'firstOrCreate') {
                $message .= "{$short_class}::firstOrCreate(['email' => \$email], \$data);\n";
            } elseif ($this->method_name === 'updateOrCreate') {
                $message .= "{$short_class}::updateOrCreate(['id' => \$id], \$data);\n";
            }
        } else {
            if ($this->method_name === 'update') {
                $message .= "{$var_name}->update(\$request->all());\n";
                $message .= "{$var_name}->update(\$request->validated());\n";
            } else {
                $message .= "{$var_name}->fill(\$request->all());\n";
                $message .= "{$var_name}->forceFill(\$data);\n";
            }
        }
        
        $message .= "\n✅ CORRECT (explicit field assignment):\n";
        
        // Generate example based on attempted fields
        if (!empty($this->attempted_fields)) {
            if ($is_static || $this->method_name === 'update') {
                if ($this->method_name === 'update') {
                    $message .= "{$var_name} = {$short_class}::find(\$id);\n";
                } else {
                    $message .= "{$var_name} = new {$short_class}();\n";
                }
                
                foreach ($this->attempted_fields as $field) {
                    $message .= "{$var_name}->{$field} = \$request->input('{$field}');\n";
                }
                $message .= "{$var_name}->save();\n";
            }
        } else {
            // Generic example
            $message .= "{$var_name} = new {$short_class}();\n";
            $message .= "{$var_name}->name = \$request->input('name');\n";
            $message .= "{$var_name}->email = \$request->input('email');\n";
            $message .= "{$var_name}->status = \$request->input('status');\n";
            $message .= "{$var_name}->save();\n";
        }
        
        $message .= "\n";
        $message .= "For finding and updating:\n";
        $message .= "{$var_name} = {$short_class}::where('email', \$email)->first();\n";
        $message .= "if (!{$var_name}) {\n";
        $message .= "    {$var_name} = new {$short_class}();\n";
        $message .= "    {$var_name}->email = \$email;\n";
        $message .= "}\n";
        $message .= "{$var_name}->name = \$request->input('name');\n";
        $message .= "{$var_name}->save();\n";
        
        $message .= "\n";
        $message .= "Benefits of explicit assignment:\n";
        $message .= "• Clear visibility of what fields are being set\n";
        $message .= "• Protection against unexpected field injection\n";
        $message .= "• Easier to debug and maintain\n";
        $message .= "• Type safety with IDE autocompletion\n";
        $message .= "• No need for \$fillable or \$guarded arrays\n";
        
        $message .= "\n";
        $message .= "Note: The \$fillable property is not needed in RSpade models.\n";
        $message .= "Remove any \$fillable or \$guarded properties from your models.\n";
        
        return $message;
    }
    
    /**
     * Get the model class that triggered the exception
     * 
     * @return string
     */
    public function get_model_class()
    {
        return $this->model_class;
    }
    
    /**
     * Get the fields that were attempted to be mass assigned
     * 
     * @return array
     */
    public function get_attempted_fields()
    {
        return $this->attempted_fields;
    }
}