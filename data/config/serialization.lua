return {
        enabled = true,

        -- Optional: add a short prefix to help visually parse serials
        serial_prefix = "NX-",

        -- Exclude noisy items from serialization:
        exclude = {
                stackable = true,       -- true = skip stackables (e.g., gold coins)
                fluid = true,           -- skip fluid containers
                corpse = true,          -- skip dead bodies themselves
        },

        -- Optional: per-itemid blacklist if needed
        blacklist_itemids = {
                -- [3031] = true, -- gold coin
        },

        -- Controls which players can view serialized information
        view = {
                min_group_id = 3,       -- only players with group id >= this see serials
                label = "Serial",       -- text label shown in UI/look
                enable_in_look = true,  -- gate inside onLook text flow
                enable_in_tooltip = true,       -- if your UI/tooltip builder calls into Serialization
        },
}
