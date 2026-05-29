import { forwardRef, JSX } from 'react';
import classnames from 'classnames';
import { commandList } from '../../config/command';

interface TooltipCommandProps extends React.HTMLAttributes<HTMLSpanElement> {
  commands: string[];
}

const TooltipCommand = forwardRef<HTMLSpanElement, TooltipCommandProps>(
  ({ commands, className, ...props }, ref): JSX.Element => {
    return (
      <span className={classnames('acms-admin-tooltip-command', className)} ref={ref} {...props}>
        {commands.map((command) => (
          <kbd key={command}>{commandList.find((c) => c.id === command)?.command || command}</kbd>
        ))}
      </span>
    );
  }
);

TooltipCommand.displayName = 'TooltipCommand';

export default TooltipCommand;
