<?php

/**
 * Interface copy, in Gujarati.
 *
 * Mirrors lang/en/ui.php key for key; a test fails if the two drift apart.
 *
 * House terms kept as they are said in the office rather than translated
 * literally: કાર્યાલય (Karyalay), પ્રસંગ (Prasang), મંદિર (Mandir), સત્સંગ
 * (Satsang). Roles and lifecycle states follow the same house usage.
 *
 * This copy still wants a read-through by a native speaker on their team
 * before it is treated as final; the mechanism works either way, and a word
 * can be corrected here alone.
 */
return [

    'common' => [
        'cancel' => 'રદ કરો',
        'save' => 'સાચવો',
        'apply' => 'લાગુ કરો',
        'close' => 'બંધ કરો',
        'search' => 'શોધો',
        'loading' => 'લોડ થાય છે…',
        'language' => 'ભાષા',
        'english' => 'English',
        'gujarati' => 'ગુજરાતી',
    ],

    'auth' => [
        'welcome_back' => 'ફરી આપનું સ્વાગત છે',
        'email_address' => 'ઈમેલ સરનામું',
        'email' => 'ઈમેલ',
        'password' => 'પાસવર્ડ',
        'remember_me' => 'મને યાદ રાખો',
        'forgot_password' => 'પાસવર્ડ ભૂલી ગયા?',
        'sign_in' => 'સાઇન ઇન',
        'signing_in' => 'સાઇન ઇન થાય છે…',
        'no_account' => 'ખાતું નથી?',
        'create_one' => 'નવું બનાવો',
        'reset_title' => 'પાસવર્ડ ફરી સેટ કરો',
        'reset_intro' => 'આપના :portal ખાતાનું ઈમેલ દાખલ કરો. ચાલુ ખાતાંને એક જ વાર વાપરી શકાય એવી લિંક મોકલવામાં આવશે.',
        'send_reset_link' => 'લિંક મોકલો',
        'sending' => 'મોકલાય છે…',
        'back_to_sign_in' => 'સાઇન ઇન પર પાછા જાઓ',
        'choose_new_password' => 'નવો પાસવર્ડ પસંદ કરો',
        'new_password' => 'નવો પાસવર્ડ',
        'confirm_password' => 'પાસવર્ડ ફરી લખો',
        'reset_password' => 'પાસવર્ડ ફરી સેટ કરો',
        'two_factor_title' => 'બે સ્તરની ખાતરી',
        'two_factor_intro' => 'ઓથેન્ટિકેટર એપનો ૬ અંકનો કોડ અથવા વણવપરાયેલ એક રિકવરી કોડ દાખલ કરો.',
        'verification_code' => 'ખાતરી કોડ',
        'verify' => 'ખાતરી કરો',
        'sign_out' => 'સાઇન આઉટ',
    ],

    'nav' => [
        'browse' => 'ફાઇલો જુઓ',
        'folders' => 'ફોલ્ડર',
        'upload' => 'એકસાથે અપલોડ',
        'access' => 'પ્રવેશ વિનંતી',
        'reports' => 'ડેશબોર્ડ અને અહેવાલ',
        'integrations' => 'ઇન્ટિગ્રેશન અને સ્થિતિ',
        'guide' => 'વપરાશ માર્ગદર્શિકા',
        'settings' => 'સેટિંગ',
    ],

    'sidebar' => [
        'categories' => 'કેટેગરી',
        'add_category' => 'મુખ્ય કેટેગરી ઉમેરો',
        'add_subcategory' => 'સબ-કેટેગરી ઉમેરો',
        'add_subcategory_under' => ':name હેઠળ સબ-કેટેગરી ઉમેરો',
        'rename' => ':name નું નામ બદલો',
        'edit' => ':name માં ફેરફાર કરો',
        'search_categories' => 'કેટેગરી / સબ-કેટેગરી શોધો…',
        'search_categories_label' => 'કેટેગરી શોધો',
        'all_files' => 'બધી ફાઇલો',
        'no_subcategory' => 'હજી કોઈ સબ-કેટેગરી નથી',
        'no_category_match' => '“:query” સાથે મળતી કોઈ કેટેગરી કે સબ-કેટેગરી નથી.',
    ],

    'header' => [
        'search_placeholder' => 'ફાઇલ, પ્રસંગ, વ્યક્તિ, સ્થળ શોધો…',
        'notifications' => 'સૂચનાઓ',
        'view_all' => 'બધી જુઓ',
        'mark_all_read' => 'બધી વાંચેલી ગણો',
        'no_notifications' => 'કોઈ સૂચના નથી.',
        'simulate' => 'ભૂમિકા અજમાવો:',
        'logout' => 'લોગઆઉટ',
    ],

    'roles' => [
        'super-admin' => 'સુપર એડમિન',
        'department-admin' => 'વિભાગ એડમિન',
        'department-operator' => 'વિભાગ ઓપરેટર',
        'viewer' => 'વાચક',
    ],

    'browse' => [
        'title' => 'શોધ અને સંશોધન',
        'subtitle_default' => 'બધી કેટેગરીની તમામ ફાઇલો',
        'subtitle_favorites' => 'આપની પસંદીદા ફાઇલો',
        'subtitle_recent' => 'હમણાં જોયેલી ફાઇલો',
        'subtitle_search' => '“:query” માટેનાં પરિણામ',
        'subtitle_in_subcategory' => 'પસંદ કરેલી સબ-કેટેગરીમાં :type',
        'subtitle_in_category' => 'પસંદ કરેલી કેટેગરીમાં :type',
        'subtitle_of_type' => 'બધાં :type',
        'total_storage' => 'વપરાયેલી કુલ જગ્યા',
        'total_files' => 'કુલ ફાઇલો',
        'current_role' => 'હાલની ભૂમિકા',
        'select_all' => 'બધું પસંદ કરો',
        'no_matching_files' => 'મળતી કોઈ ફાઇલ નથી',
        'clear_all' => 'બધું હટાવો',
        'scope' => 'વ્યાપ',
        'everywhere' => 'બધે',
        'current_department' => 'હાલનો વિભાગ',
        'advanced_filters' => 'વિગતવાર ફિલ્ટર',
        'name_current_search' => 'આ શોધને નામ આપો…',
        'apply_saved_search' => 'સાચવેલી શોધ વાપરો…',
        'all' => 'બધું',
        'favorites' => 'પસંદીદા',
        'recently_viewed' => 'હમણાં જોયેલા',
        'save' => 'સાચવો',
    ],

    'filters' => [
        'all_files' => 'બધી ફાઇલો',
        'images' => 'ચિત્રો',
        'videos' => 'વીડિયો',
        'audio' => 'ઓડિયો',
        'documents' => 'દસ્તાવેજ',
        'all_departments' => 'બધા વિભાગ',
        'all_policies' => 'બધી પ્રવેશ નીતિ',
        'any_year' => 'કોઈ પણ વર્ષ',
        'any_country' => 'કોઈ પણ દેશ',
        'any_state' => 'કોઈ પણ રાજ્ય',
        'any_city' => 'કોઈ પણ શહેર',
        'any_mandir' => 'કોઈ પણ મંદિર',
        'any_event' => 'કોઈ પણ પ્રસંગ',
        'any_person' => 'કોઈ પણ વ્યક્તિ',
        'any_language' => 'કોઈ પણ ભાષા',
        'any_media_type' => 'કોઈ પણ મીડિયા પ્રકાર',
        'any_source' => 'કોઈ પણ સ્રોત',
        'any_status' => 'કોઈ પણ સ્થિતિ',
        'any_uploader' => 'કોઈ પણ અપલોડ કરનાર',
        'uploaded_from' => 'આ તારીખથી',
        'uploaded_to' => 'આ તારીખ સુધી',
    ],

    'fields' => [
        'year' => 'વર્ષ',
        'country' => 'દેશ',
        'state' => 'રાજ્ય',
        'city' => 'શહેર',
        'mandir' => 'મંદિર',
        'event' => 'પ્રસંગ',
        'person' => 'ગુરુજી / વ્યક્તિ',
        'language' => 'ભાષા',
        'media_type' => 'મીડિયા પ્રકાર',
        'category' => 'કેટેગરી',
        'subcategory' => 'સબ-કેટેગરી',
        'department' => 'વિભાગ',
        'source' => 'સ્રોત',
        'status' => 'સ્થિતિ',
        'uploaded_by' => 'અપલોડ કરનાર',
        'access' => 'પ્રવેશ',
    ],

    'policy' => [
        'public' => 'જાહેર',
        'protected' => 'સુરક્ષિત',
        'private' => 'ખાનગી',
    ],

    'status' => [
        'draft' => 'ડ્રાફ્ટ',
        'review' => 'સમીક્ષા',
        'approved' => 'મંજૂર',
        'published' => 'પ્રકાશિત',
        'active' => 'ચાલુ',
        'inactive' => 'બંધ',
        'archived' => 'સંગ્રહિત',
        'broken' => 'બગડેલી',
    ],

    'sort' => [
        'best_match' => 'સૌથી મળતું',
        'newest' => 'નવીનતમ',
        'oldest' => 'જૂનામાં જૂનું',
        'largest' => 'સૌથી મોટું',
        'recently_updated' => 'હમણાં સુધારેલું',
        'name_asc' => 'નામ A–Z',
        'name_desc' => 'નામ Z–A',
    ],

    'source' => [
        'local' => 'સીધું અપલોડ',
        'nas' => 'NAS',
        'google-drive' => 'Google Drive',
        'youtube' => 'YouTube',
    ],
];
