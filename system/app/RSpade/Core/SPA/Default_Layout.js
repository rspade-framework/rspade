/**
 * Default_Layout - Minimal passthrough layout for actions without explicit @layout
 *
 * This layout provides the required $sid="content" element but adds no visual structure.
 * Used when an action doesn't specify any @layout decorator.
 */
class Default_Layout extends Spa_Layout {
    // No custom behavior - just provides the content container
}
