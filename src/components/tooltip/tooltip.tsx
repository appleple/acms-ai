import { forwardRef } from 'react';
import { Tooltip as ReactTooltip, type TooltipRefProps } from 'react-tooltip';
import classnames from 'classnames';

/**
 * ツールチップコンポーネント
 * clickable を true にすることで、ツールチップ内のリンクをクリックできるようになる
 */
const Tooltip = forwardRef<TooltipRefProps, React.ComponentPropsWithRef<typeof ReactTooltip>>(
  ({ className, ...props }, ref) => {
    return <ReactTooltip ref={ref} className={classnames('acms-admin-tooltip', className)} clickable {...props} />;
  }
);

Tooltip.displayName = 'Tooltip';

export default Tooltip;
