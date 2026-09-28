-- 005: each restaurant's own settings — the week, the trade settings (D2: trades are settings in the
-- application, per restaurant), reminders, overtime — and the business-wide defaults a new site starts from.
BEGIN;

CREATE TABLE app_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    default_week_start          smallint NOT NULL DEFAULT 1 CHECK (default_week_start BETWEEN 0 AND 6),   -- 0 Sunday … 6 Saturday
    default_currency            text NOT NULL DEFAULT 'USD' CHECK (default_currency ~ '^[A-Z]{3}$'),
    updated_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO app_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER app_settings_touch BEFORE UPDATE ON app_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per site, created with the site (ts_site_materialise(), below). The trade settings are FR-S6.
CREATE TABLE site_settings (
    scope_id                    bigint PRIMARY KEY REFERENCES sites(scope_id) ON DELETE CASCADE,
    week_start                  smallint NOT NULL DEFAULT 1 CHECK (week_start BETWEEN 0 AND 6),
    currency                    text NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),
    -- which exchanges exist at all
    allow_offer                 boolean NOT NULL DEFAULT true,    -- put my shift up for grabs (drop)
    allow_pickup                boolean NOT NULL DEFAULT true,    -- take an offered or open shift
    allow_swap                  boolean NOT NULL DEFAULT true,    -- my shift for a named colleague's shift
    allow_give                  boolean NOT NULL DEFAULT true,    -- my shift to one named colleague
    -- which need a manager: always | on_warning (a soft rule fired) | never
    approval_pickup             text NOT NULL DEFAULT 'on_warning' CHECK (approval_pickup IN ('always', 'on_warning', 'never')),
    approval_swap               text NOT NULL DEFAULT 'on_warning' CHECK (approval_swap   IN ('always', 'on_warning', 'never')),
    approval_give               text NOT NULL DEFAULT 'on_warning' CHECK (approval_give   IN ('always', 'on_warning', 'never')),
    cutoff_minutes              integer NOT NULL DEFAULT 120 CHECK (cutoff_minutes BETWEEN 0 AND 10080),   -- no exchange closer than this to the start
    shift_lead_approves_same_day boolean NOT NULL DEFAULT true,
    claim_mode                  text NOT NULL DEFAULT 'first' CHECK (claim_mode IN ('first', 'manager_chooses')),
    offer_expires               text NOT NULL DEFAULT 'at_start' CHECK (offer_expires IN ('at_start', 'at_cutoff')),
    -- availability and reminders
    availability_needs_approval boolean NOT NULL DEFAULT true,
    reminder_minutes_before     integer NOT NULL DEFAULT 120 CHECK (reminder_minutes_before BETWEEN 0 AND 2880),
    -- labor
    overtime_weekly_hours       numeric(5,2) NOT NULL DEFAULT 40 CHECK (overtime_weekly_hours > 0),
    overtime_multiplier         numeric(4,2) NOT NULL DEFAULT 1.5 CHECK (overtime_multiplier >= 1),
    rule_preset                 text NOT NULL DEFAULT 'generic',
    updated_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER site_settings_touch BEFORE UPDATE ON site_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Day-parts (lunch, dinner, …) per site: the forecast (db/011) and the day view speak in them.
CREATE TABLE day_parts (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    key         text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,29}$'),
    name        text NOT NULL,
    starts_at   time NOT NULL,
    ends_at     time NOT NULL,                  -- may be before starts_at (runs past midnight)
    sort_order  integer NOT NULL DEFAULT 0,
    archived_at timestamptz,
    service_name text,                          -- the name Reservations gives this service (Lunch, Dinner, Brunch): how its covers land in the forecast (K7)
    UNIQUE (scope_id, key)
);

GRANT SELECT, UPDATE ON app_settings TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE ON site_settings TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE ON day_parts TO txtschedules_rw;

COMMIT;
