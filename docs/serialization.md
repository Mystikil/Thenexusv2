# Item Serialization

The item serialization system assigns a unique serial number to every newly created item after server startup. Serials are stored as item custom attributes so they persist across saves, trades, containers, and movements.

## Configuration

Settings live in [`data/config/serialization.lua`](../data/config/serialization.lua):

- `enabled`: master switch for the entire feature.
- `serial_prefix`: optional prefix prepended to every serial.
- `exclude`: toggle skipping of noisy categories (stackable items, fluid containers, corpses).
- `blacklist_itemids`: per-itemid table for precise exclusions.

Reload the configuration by restarting the server or calling `Serialization.reloadConfig()` from a script.

## Serial Format

Serials are generated with a ULID-style, Crockford Base32 string that preserves creation order. The final serial is `<serial_prefix><ULID>` (e.g., `NX-01HCHB8MB4V7D1B12N3X1W3QF`).

## Runtime Hooks

A global `AddItem` move event (`data/movements/scripts/serialization_on_add.lua`) assigns serials the moment an item enters the world or a container. Existing items present before startup remain untouched.

## Commands

Two talkactions help with inspection and manual remediation:

- `/serial [slot]` – show the serial for the item held in the default hand slots or the specified equipment slot.
- `/reserial [slot]` – (access 4+) force a serial onto the targeted item if it does not already have one.

## UI Integration

Tooltips or inspect popups can display the serial by reading `item:getCustomAttribute("serial")` and appending a neutral-colored line (for example, `Serial: NX-…`). This keeps the feature optional for clients without UI changes.

## Testing Checklist

- Toggle `enabled` off and spawn items: no serial attributes appear.
- Enable the feature, kill monsters, or create items: non-excluded items receive serials immediately.
- Verify excluded categories remain untouched according to config.
- Confirm pre-existing items retain `serial = nil` after enabling the feature.
- `/serial` displays the stored value or `none`; `/reserial` refuses to overwrite existing serials.

