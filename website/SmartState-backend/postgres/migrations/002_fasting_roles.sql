INSERT INTO user_roles (role_id, role_name) VALUES
    (4, 'PI'),
    (5, 'Non-PHI')
ON CONFLICT (role_id) DO NOTHING;
