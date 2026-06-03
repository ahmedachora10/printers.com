import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            {/* paper feeding out the top */}
            <path
                d="M7 8V4.5C7 3.94772 7.44772 3.5 8 3.5H16C16.5523 3.5 17 3.94772 17 4.5V8"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {/* printer body */}
            <path
                d="M5 8H19C20.1046 8 21 8.89543 21 10V15C21 16.1046 20.1046 17 19 17H17V14H7V17H5C3.89543 17 3 16.1046 3 15V10C3 8.89543 3.89543 8 5 8Z"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinejoin="round"
            />
            {/* printed sheet output */}
            <path
                d="M7 14H17V19.5C17 20.0523 16.5523 20.5 16 20.5H8C7.44772 20.5 7 20.0523 7 19.5V14Z"
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {/* ink status dot */}
            <circle cx="17.5" cy="11" r="1" fill="currentColor" />
        </svg>
    );
}
