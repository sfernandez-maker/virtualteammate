// Register Virtual Assistant Custom Post Type
function register_va_post_type() {
    register_post_type('virtual_assistant',
        array(
            'labels' => array(
                'name' => 'Virtual Assistants',
                'singular_name' => 'Virtual Assistant',
                'add_new' => 'Add New VA',
                'add_new_item' => 'Add New Virtual Assistant'
            ),
            'public' => true,
            'has_archive' => true,
            'menu_icon' => 'dashicons-businessperson',
            'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
            'show_in_rest' => true
        )
    );
}
add_action('init', 'register_va_post_type');

// Department to Skills Mapping
function get_department_skills($department) {
    $skills_mapping = array(
        'healthcare' => array(
            'Medical Administrative Support',
            'Medical Receptionist', 
            'Medical Biller',
            'Medical Scribe'
        ),
        'administrative' => array(
            'Administrative Assistant',
            'Executive Assistant',
            'Personal Assistant'
        ),
        'business-intelligence' => array(
            'HubSpot Specialist',
            'BI Developer',
            'Data Analyst',
            'Quality Assurance Analyst'
        ),
        'customer-service' => array(
            'Client Services Representative',
            'Customer Service Specialist', 
            'Receptionist'
        ),
        'finance' => array(
            'Bookkeeper',
            'Account',
            'Billing Coordinator',
            'Accounts Payable Specialist'
        ),
        'dental' => array(
            'Dental Assistant',
            'Dental Biller'
        ),
        'marketing' => array(
            'Graphic Designer',
            'Marketing Coordinator',
            'Social Media Manager',
            'E-commerce Specialist',
            'Marketing Manager'
        ),
        'sales' => array(
            'Sales Representative'
        )
    );
    
    return isset($skills_mapping[$department]) ? $skills_mapping[$department] : array();
}

// Function to detect primary skill from excerpt
function detect_primary_skill($excerpt, $department) {
    $department_skills = get_department_skills($department);
    
    foreach ($department_skills as $skill) {
        if (stripos($excerpt, $skill) !== false) {
            return $skill;
        }
    }
    
    return 'General ' . ucfirst($department);
}