-- Standardize credentials for every existing account.
-- Plain-text temporary password: APPRAISIA_NDMC
-- Changing this password later from Profile is optional.
UPDATE users
SET password_hash = '$2y$10$nWYKxP4i4oPD4.ObVjDZaeRJVwH6LjYm5l4dPoOC.4cZ/d/U83Z86',
    must_change_password = 0;
