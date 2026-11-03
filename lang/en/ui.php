<?php

/**
 * Interface copy, in English.
 *
 * This is the source catalogue: every key starts life here, and lang/gu/ui.php
 * carries the same keys in Gujarati. A test asserts the two stay in step, so a
 * new string added here without its translation fails the suite rather than
 * appearing untranslated on a Gujarati screen.
 *
 * Phase 1 covers what a person meets first: the sign-in screens, the
 * navigation, the header and the Browse screen. Upload, access and the admin
 * panels follow in later phases.
 */
return [

    'common' => [
        'cancel' => 'Cancel',
        'save' => 'Save',
        'apply' => 'Apply',
        'close' => 'Close',
        'search' => 'Search',
        'loading' => 'Loading…',
        'language' => 'Language',
        'english' => 'English',
        'gujarati' => 'Gujarati',
    ],

    'auth' => [
        'welcome_back' => 'Welcome back',
        'email_address' => 'Email address',
        'email' => 'Email',
        'password' => 'Password',
        'remember_me' => 'Remember me',
        'forgot_password' => 'Forgot password?',
        'sign_in' => 'Sign in',
        'signing_in' => 'Signing in…',
        'no_account' => "Don't have an account?",
        'create_one' => 'Create one',
        'reset_title' => 'Reset password',
        'reset_intro' => 'Enter your :portal account email. Active accounts receive a single-use reset link.',
        'send_reset_link' => 'Send reset link',
        'sending' => 'Sending…',
        'back_to_sign_in' => 'Back to sign in',
        'choose_new_password' => 'Choose a new password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm password',
        'reset_password' => 'Reset password',
        'two_factor_title' => 'Two-factor verification',
        'two_factor_intro' => 'Enter the 6-digit authenticator code or one unused recovery code.',
        'verification_code' => 'Verification code',
        'verify' => 'Verify',
        'sign_out' => 'Sign out',
    ],

    'nav' => [
        'browse' => 'Browse Files',
        'folders' => 'Folders',
        'upload' => 'Batch Upload',
        'access' => 'Access Requests',
        'reports' => 'Dashboards & Reports',
        'integrations' => 'Integrations & Health',
        'guide' => 'User Guide Manual',
        'settings' => 'Settings',
    ],

    'sidebar' => [
        'categories' => 'Categories',
        'add_category' => 'Add Main Category',
        'add_subcategory' => 'Add Sub-Category',
        'add_subcategory_under' => 'Add sub-category under :name',
        'rename' => 'Rename :name',
        'edit' => 'Edit :name',
        'search_categories' => 'Search category / sub-category…',
        'search_categories_label' => 'Search categories',
        'all_files' => 'All Files',
        'no_subcategory' => 'No sub-category yet',
        'no_category_match' => 'No category or sub-category matches “:query”.',
    ],

    'header' => [
        'search_placeholder' => 'Search files, events, people, locations…',
        'notifications' => 'Notifications',
        'view_all' => 'View all',
        'mark_all_read' => 'Mark all read',
        'no_notifications' => 'No notifications.',
        'simulate' => 'Simulate:',
        'logout' => 'Logout',
    ],

    'roles' => [
        'super-admin' => 'Super Admin',
        'department-admin' => 'Department Admin',
        'department-operator' => 'Department Operator',
        'viewer' => 'Viewer',
    ],

    'browse' => [
        'title' => 'Search & Discovery',
        'subtitle_default' => 'Showing all files across categories',
        'subtitle_favorites' => 'Your favorite assets',
        'subtitle_recent' => 'Recently viewed assets',
        'subtitle_search' => 'Search results for “:query”',
        'subtitle_in_subcategory' => ':type in selected sub-category',
        'subtitle_in_category' => ':type in selected category',
        'subtitle_of_type' => 'Showing all :type',
        'total_storage' => 'Total Storage Used',
        'total_files' => 'Total Files',
        'current_role' => 'Current Session Role',
        'select_all' => 'Select All',
        'no_matching_files' => 'No matching files',
        'clear_all' => 'Clear all',
        'scope' => 'Scope',
        'everywhere' => 'Everywhere',
        'current_department' => 'Current Department',
        'advanced_filters' => 'Advanced metadata filters',
        'name_current_search' => 'Name current search…',
        'apply_saved_search' => 'Apply a saved search…',
        'all' => 'All',
        'favorites' => 'Favorites',
        'recently_viewed' => 'Recently Viewed',
        'save' => 'Save',
    ],

    'filters' => [
        'all_files' => 'All Files',
        'images' => 'Images',
        'videos' => 'Videos',
        'audio' => 'Audio',
        'documents' => 'Sheets/Docs',
        'all_departments' => 'All Departments',
        'all_policies' => 'All Access Policies',
        'any_year' => 'Any year',
        'any_country' => 'Any country',
        'any_state' => 'Any state',
        'any_city' => 'Any city',
        'any_mandir' => 'Any mandir',
        'any_event' => 'Any event',
        'any_person' => 'Any person',
        'any_language' => 'Any language',
        'any_media_type' => 'Any media type',
        'any_source' => 'Any source',
        'any_status' => 'Any status',
        'any_uploader' => 'Any uploader',
        'uploaded_from' => 'Uploaded From',
        'uploaded_to' => 'Uploaded To',
    ],

    'fields' => [
        'year' => 'Year',
        'country' => 'Country',
        'state' => 'State',
        'city' => 'City',
        'mandir' => 'Mandir',
        'event' => 'Event / Prasang',
        'person' => 'Guruji / Person',
        'language' => 'Language',
        'media_type' => 'Media Type',
        'category' => 'Category',
        'subcategory' => 'Sub-category',
        'department' => 'Department',
        'source' => 'Source',
        'status' => 'Status',
        'uploaded_by' => 'Uploaded By',
        'access' => 'Access',
    ],

    'policy' => [
        'public' => 'Public',
        'protected' => 'Protected',
        'private' => 'Private',
    ],

    'status' => [
        'draft' => 'Draft',
        'review' => 'Review',
        'approved' => 'Approved',
        'published' => 'Published',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'archived' => 'Archived',
        'broken' => 'Broken',
    ],

    'sort' => [
        'best_match' => 'Best Match',
        'newest' => 'Newest',
        'oldest' => 'Oldest',
        'largest' => 'Largest',
        'recently_updated' => 'Recently Updated',
        'name_asc' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
    ],

    'source' => [
        'local' => 'Local Upload',
        'nas' => 'NAS',
        'google-drive' => 'Google Drive',
        'youtube' => 'YouTube',
    ],
];
