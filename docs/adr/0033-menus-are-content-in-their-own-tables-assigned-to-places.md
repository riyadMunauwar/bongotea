# Menus are content in their own tables, assigned to places, not a settings group

A **Menu** is a row in `menus` with its links in `menu_items` — nested by `parent_id`, ordered by
`position` among siblings, up to three levels deep. A link points at its target by reference (a
Page through the `linkable` morph) or holds an anchor or a typed address; a Heading points at
nothing and only opens its children. A place — the header today, the footer columns and bottom
line next — is a settings group holding the id of the Menu it draws (`HeaderSettings::$menuId`).
Menus are built in our own drag-and-drop builder under Pages and gated on `author pages`, because
they are storefront content kept in step with the Pages they link, not configuration.

Until now the header and footer were two fixed JSON shapes in the `menus` settings row (ADR-0012),
edited with nested Repeaters one level deep, behind `manage settings`. That shape could not grow a
third level, could not let one Menu serve two places, and put every link inside one row that had to
be sanitised as a whole on every request.

**The tree is validated whole and replaced in one transaction.** The builder holds the tree in the
browser and submits all of it on Save. `MenuTree::save()` checks the whole tree — depth, each
item's target against its type, that every Page exists, that every address is one the storefront
draws — and refuses it pointing at the first offending item, writing nothing. Otherwise it deletes
the old items and inserts the new inside one transaction and writes one activity entry. Partial
writes (move this item, rename that one) were rejected: they would make the storefront draw a
half-finished rearrangement and the history read as every drag.

**"Assigned" is asked of the places.** A Menu holds nothing about where it appears; each place group
answers `placesOf()` and `MenuPlaces` asks them all. Deleting a Menu a place draws is refused with
the places named, in the model as well as on screen. A `place` column or a pivot was rejected: it
would be a second statement of what the place's own setting already says, and the two could
disagree.

**Considered options.** Keeping Menus in the settings row and widening its JSON was rejected for
the reasons above. Filament's nested Repeaters, or a third-party tree plugin, were rejected: the
first cannot drag a branch between levels, and the second is a dependency for something
SortableJS — already shipped with Filament — does in one component.

## Consequences

- `StorefrontMenus` reads the header's items in one query and builds the tree in memory, dropping a
  link whose Page is unpublished or gone, a disallowed address, and a Heading left with no children.
- The announcement bar moved into `HeaderSettings` with the header's Menu; `AnnouncementSettings`
  and Settings → Announcement are gone. Their row is left orphaned and never read.
- No data was migrated from the old `menus` row: an existing installation's header draws without a
  navigation row until a Menu is built and assigned on Pages → Header. `bongotea:install` builds
  and assigns the mockup's.
- The footer keeps reading the old row until it becomes a place of its own.
